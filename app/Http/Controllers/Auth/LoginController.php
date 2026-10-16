<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\LoginCodeService;
use App\Support\Portal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

/**
 * メール+6桁コードによるログイン(社内/お客様の両ポータル共通)
 */
class LoginController extends Controller
{
    /** 同じ宛先への認証コードの送信が多すぎる場合のメッセージ(回数制限のミドルウェアと同じ文言) */
    private const TOO_MANY_SENDS = '試行回数が多すぎます。しばらく時間をおいてから再度お試しください。';

    public function __construct(
        private readonly LoginCodeService $loginCodes,
    ) {}

    /**
     * メールアドレス入力画面
     */
    public function showEmailForm(): View
    {
        return view('auth.login');
    }

    /**
     * 認証コードを送信してコード入力画面へ
     */
    public function sendCode(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            ['email' => ['required', 'email', 'max:255']],
            [],
            ['email' => 'メールアドレス']
        );
        $email = LoginCodeService::normalizeEmail($validated['email']);

        try {
            $sent = $this->loginCodes->send($email, $this->role());
        } catch (Throwable $e) {
            return $this->failed($e, '認証コードの送信に失敗しました');
        }
        if (! $sent) {
            return back()->withInput()->with('error', self::TOO_MANY_SENDS);
        }

        $request->session()->put($this->sessionKey(), $email);

        return redirect()->to(Portal::route('login.code'))
            ->with('status', '登録済みのメールアドレスの場合、認証コードを送信しました。メールをご確認ください。');
    }

    /**
     * 認証コード入力画面
     */
    public function showCodeForm(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get($this->sessionKey());
        if ($email === null) {
            return redirect()->to(Portal::route('login'));
        }

        return view('auth.code', ['email' => $email]);
    }

    /**
     * 認証コードを検証してログインする
     */
    public function verifyCode(Request $request): RedirectResponse
    {
        $email = $request->session()->get($this->sessionKey());
        if ($email === null) {
            return redirect()->to(Portal::route('login'));
        }

        $validated = $request->validate(
            ['code' => ['required', 'digits:6']],
            [],
            ['code' => '認証コード']
        );

        try {
            $user = $this->loginCodes->verify($email, $this->role(), $validated['code']);
        } catch (Throwable $e) {
            return $this->failed($e, '認証コードの検証に失敗しました');
        }

        if ($user === null) {
            $message = $this->loginCodes->tooManyFailures($email, $this->role())
                ? '認証コードの入力ミスが続いたため、一時的にログインできません。1時間ほどおいてから再度お試しください。'
                : '認証コードが正しくないか、有効期限が切れています。';

            return back()->withErrors(['code' => $message]);
        }

        // このポータルのガードにだけログインする(別ポータルのログイン状態には影響しない)
        Auth::guard(portal())->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->forget($this->sessionKey());

        return redirect()->to($this->intendedUrl($request));
    }

    /**
     * 認証コードを再送する
     */
    public function resend(Request $request): RedirectResponse
    {
        $email = $request->session()->get($this->sessionKey());
        if ($email === null) {
            return redirect()->to(Portal::route('login'));
        }

        try {
            $sent = $this->loginCodes->send($email, $this->role());
        } catch (Throwable $e) {
            return $this->failed($e, '認証コードの再送に失敗しました');
        }
        if (! $sent) {
            return back()->with('error', self::TOO_MANY_SENDS);
        }

        // 再送すると以前のコードは無効になるため、遅れて届いた古いメールのコードを入力しないよう案内する
        return back()->with('status', '新しい認証コードを送信しました。以前のコードは使えなくなりました。最新のメールに記載のコードを入力してください。');
    }

    /**
     * ログアウト。セッション全体を破棄(invalidate)すると同じブラウザの別ポータルまでログアウトされるため、
     * このポータルのガードだけをログアウトさせる。
     * セッションIDは変えるがCSRFトークンは変えない(別タブで開いている別ポータルのフォームが419エラーにならないように)
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard(portal())->logout();
        $request->session()->migrate(true);

        return redirect()->to(Portal::route('login'))->with('status', 'ログアウトしました。');
    }

    /**
     * ログイン後の遷移先。ログイン前に開こうとしていたURLが同じポータルのものならそこへ戻す
     */
    private function intendedUrl(Request $request): string
    {
        $intended = $request->session()->get('url.intended');
        if (is_string($intended) && Portal::ownsUrl($intended, portal())) {
            $request->session()->forget('url.intended');

            return $intended;
        }

        return Portal::route('home');
    }

    private function role(): UserRole
    {
        return UserRole::from(portal());
    }

    private function sessionKey(): string
    {
        return 'login.'.portal().'.email';
    }
}
