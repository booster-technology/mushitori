<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

use function Illuminate\Support\defer;

/**
 * メール+6桁コードによるログインの、コード発行と検証を担当する
 */
class LoginCodeService
{
    /** コードの有効期限(分) */
    public const EXPIRES_MINUTES = 10;

    /** 1つのコードに対して許す入力ミスの回数 */
    public const MAX_ATTEMPTS = 5;

    /**
     * 1アカウントあたりの入力ミスの上限(コードを再発行しても通算で数える)。
     * これを超えると一定時間ログインできなくなり、再送を繰り返した総当たりを防ぐ
     */
    public const MAX_FAILURES_PER_ACCOUNT = 10;

    /** 入力ミスの通算回数を数える期間(秒) */
    public const FAILURE_WINDOW_SECONDS = 3600;

    /**
     * 1アカウントあたりの入力ミスの1日の上限。
     * 1時間ごとのロックが解けるたびに試し続ける総当たり(1日240回)を防ぐ。超えると24時間ログインできない(社内メンバーが解除できる)
     */
    public const MAX_FAILURES_PER_DAY = 30;

    /** 1日の入力ミスを数える期間(秒) */
    public const DAILY_WINDOW_SECONDS = 86400;

    /**
     * 同じ宛先への認証コードの送信回数の上限(1時間あたり)。
     * 接続元を変えながら同じ相手にメールを送り続ける嫌がらせ(メール爆撃)を防ぐ。登録の有無に関係なく数える
     */
    public const MAX_SENDS_PER_HOUR = 10;

    /**
     * 認証コードを発行してメールで送る。
     * 登録されていない・無効・ロール違いのメールアドレスでも例外にせず何もしない
     * (画面の反応から登録有無を推測されないようにするため)。
     * メール送信は応答を返した後に行う(送信時間の差で登録有無を推測されないようにするため)。
     * 同じ宛先への送信が多すぎる場合は、何もせず false を返す
     */
    public function send(string $email, UserRole $role): bool
    {
        $sendKey = $this->sendKey($email);
        if (RateLimiter::tooManyAttempts($sendKey, self::MAX_SENDS_PER_HOUR)) {
            return false;
        }
        RateLimiter::hit($sendKey, 3600);

        $user = $this->findLoginableUser($email, $role);
        if ($user === null) {
            return true;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $role, $code) {
            // 以前に発行した未使用のコードは無効にして、常に最新の1つだけ使えるようにする
            LoginCode::where('user_id', $user->id)
                ->where('guard', $role->guard())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            LoginCode::create([
                'user_id' => $user->id,
                'guard' => $role->guard(),
                'code_hash' => $this->hash($code),
                'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
            ]);
        });

        defer(function () use ($user, $code) {
            try {
                Mail::to($user)->send(new LoginCodeMail($user, $code));
            } catch (Throwable $e) {
                Log::error('認証コードメールの送信に失敗しました', ['user_id' => $user->id, 'exception' => $e]);
            }
        });

        return true;
    }

    /**
     * 認証コードを検証し、正しければユーザーを返す(誤り・期限切れ・回数超過はnull)。
     * 未登録のメールアドレス・コード未発行の場合も「入力ミス」として数える(ロックされるかどうかで登録の有無を推測されないように)
     */
    public function verify(string $email, UserRole $role, string $code): ?User
    {
        $user = $this->findLoginableUser($email, $role);
        $failureKey = $this->failureKey($email, $role, $user);
        if ($this->isLocked($failureKey)) {
            return null;
        }

        // 照合する前に回数を数える(同時に大量に送信されても、上限を超えて照合させないように)。正しかった場合は後で消す
        $hourly = RateLimiter::hit($failureKey, self::FAILURE_WINDOW_SECONDS);
        $daily = RateLimiter::hit($this->dailyKey($failureKey), self::DAILY_WINDOW_SECONDS);
        if ($hourly > self::MAX_FAILURES_PER_ACCOUNT || $daily > self::MAX_FAILURES_PER_DAY) {
            return null;
        }

        $loginCode = $user === null ? null : LoginCode::where('user_id', $user->id)
            ->where('guard', $role->guard())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->latest('id')
            ->first();

        // コードごとの入力回数も照合の前にDB上で加算する(同時送信で1つのコードを5回を超えて試させないように)。
        // 加算できなければ、同時に送信された別の入力で回数を使い切った・使用済みになった
        if ($loginCode !== null && LoginCode::whereKey($loginCode->id)
            ->whereNull('used_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts') === 0) {
            $loginCode = null;
        }

        if ($loginCode === null || ! hash_equals($loginCode->code_hash, $this->hash($code))) {
            $this->recordFailure($loginCode, $role, $hourly, $daily);

            return null;
        }

        // 同時に送信されても1回しか使えないよう、未使用の場合だけ使用済みにする
        $consumed = LoginCode::whereKey($loginCode->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
        if ($consumed === 0) {
            return null;
        }

        // 本人がログインできたので、入力ミスの回数(1時間・1日)を消す
        RateLimiter::clear($failureKey);
        RateLimiter::clear($this->dailyKey($failureKey));
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    /**
     * 入力ミスが多すぎて一時的にログインできない状態か
     */
    public function tooManyFailures(string $email, UserRole $role): bool
    {
        return $this->isLocked($this->failureKey($email, $role, $this->findLoginableUser($email, $role)));
    }

    /**
     * ログインのロックを解除する(ユーザー管理画面・復旧コマンド用)。
     * 入力ミスの回数と、同じ宛先への送信回数をリセットし、すぐに認証コードを受け取ってログインできるようにする
     *
     * @param  User|null  $actor  解除した社内メンバー(コマンドから解除した場合は null)
     */
    public function unlock(User $user, ?User $actor = null): void
    {
        $failureKey = $this->failureKey($user->email, $user->role, $user);
        RateLimiter::clear($failureKey);
        RateLimiter::clear($this->dailyKey($failureKey));
        RateLimiter::clear($this->sendKey($user->email));

        Log::info('ログインのロックを解除しました', ['user_id' => $user->id, 'unlocked_by' => $actor?->id ?? 'command']);
    }

    /**
     * ロック中なら、自動で解除されるまでのおおよその分数を返す(ロックされていなければ null)
     */
    public function lockedMinutes(User $user): ?int
    {
        $key = $this->failureKey($user->email, $user->role, $user);
        $seconds = [];
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES_PER_ACCOUNT)) {
            $seconds[] = RateLimiter::availableIn($key);
        }
        if (RateLimiter::tooManyAttempts($this->dailyKey($key), self::MAX_FAILURES_PER_DAY)) {
            $seconds[] = RateLimiter::availableIn($this->dailyKey($key));
        }

        // 1時間・1日の両方でロックされている場合は、遅い方まで解除されない
        return $seconds === [] ? null : max(1, (int) ceil(max($seconds) / 60));
    }

    /**
     * 指定したユーザーのうち、ロック中のユーザーのID(ユーザー一覧の表示用)
     *
     * @param  iterable<User>  $users
     * @return list<int>
     */
    public function lockedUserIds(iterable $users): array
    {
        $ids = [];
        foreach ($users as $user) {
            if ($this->lockedMinutes($user) !== null) {
                $ids[] = $user->id;
            }
        }

        return $ids;
    }

    /**
     * 入力ミスの後始末。回数は照合の前に数え終わっているので、ここでは上限に達したコードを無効にし、ロックしたら記録する
     */
    private function recordFailure(?LoginCode $loginCode, UserRole $role, int $hourly, int $daily): void
    {
        if ($loginCode !== null) {
            LoginCode::whereKey($loginCode->id)
                ->where('attempts', '>=', self::MAX_ATTEMPTS)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        }

        // 登録済みのアカウントが入力ミスの上限に達してロックされたら、不正アクセスの兆候として記録する(コードやメールアドレスは出さない)
        if ($loginCode !== null && ($hourly === self::MAX_FAILURES_PER_ACCOUNT || $daily === self::MAX_FAILURES_PER_DAY)) {
            Log::warning('認証コードの入力ミスが続いたため、一時的にログインをロックしました', [
                'user_id' => $loginCode->user_id,
                'portal' => $role->value,
                'lock' => $daily === self::MAX_FAILURES_PER_DAY ? '24時間' : '1時間',
                'ip' => request()->ip(),
            ]);
        }
    }

    /**
     * 入力ミスが上限に達して、ロックされているか(1時間・1日のどちらか)
     */
    private function isLocked(string $failureKey): bool
    {
        return RateLimiter::tooManyAttempts($failureKey, self::MAX_FAILURES_PER_ACCOUNT)
            || RateLimiter::tooManyAttempts($this->dailyKey($failureKey), self::MAX_FAILURES_PER_DAY);
    }

    /**
     * 1日の入力ミスを数えるキー
     */
    private function dailyKey(string $failureKey): string
    {
        return $failureKey.':day';
    }

    /**
     * 入力ミスを数えるキー。登録済みのアカウントはユーザーIDで数える
     * (「Ａdmin@…」「àdmin@…」のような表記ゆれのメールアドレスを使い分けて、回数制限をすり抜けられないように)
     */
    private function failureKey(string $email, UserRole $role, ?User $user): string
    {
        $subject = $user !== null ? 'user:'.$user->id : 'email:'.sha1(self::normalizeEmail($email));

        return 'login-code-failures:'.$role->value.':'.$subject;
    }

    /**
     * 同じ宛先への送信回数を数えるキー
     */
    private function sendKey(string $email): string
    {
        return 'login-code-sends:'.sha1(self::normalizeEmail($email));
    }

    /**
     * ログイン可能(有効かつロール一致)なユーザーを探す
     */
    private function findLoginableUser(string $email, UserRole $role): ?User
    {
        $email = self::normalizeEmail($email);
        $user = User::where('email', $email)
            ->where('role', $role->value)
            ->active()
            ->first();

        // MySQLの照合順序(utf8mb4_unicode_ci)は「à」と「a」、全角と半角などを同じ文字とみなすため、
        // 入力と完全に一致する場合だけ本人とする(別の表記で他人のアカウントにコードを発行させないように)
        return $user !== null && self::normalizeEmail($user->email) === $email ? $user : null;
    }

    /**
     * メールアドレスの表記をそろえる(前後の空白を除き、小文字にする)
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * コードはAPP_KEYを鍵にしたHMACで保存し、DBが漏れても平文が分からないようにする
     */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
