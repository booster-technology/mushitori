<?php

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

/**
 * 認証コードを送信して、メールに書かれたコードを取り出す
 */
function requestLoginCode(string $portal, string $email): ?string
{
    $prefix = $portal === 'staff' ? '/staff' : '';
    test()->post($prefix.'/login', ['email' => $email])->assertRedirect($prefix.'/login/code');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

test('お客様はメールの6桁コードでログインできる', function () {
    $customer = User::factory()->customer()->create(['email' => 'customer@example.com']);

    $code = requestLoginCode('customer', 'Customer@Example.com');
    expect($code)->toMatch('/^\d{6}$/');

    $this->post('/login/code', ['code' => $code])->assertRedirect('/');

    expect(Auth::guard('customer')->id())->toBe($customer->id)
        ->and(Auth::guard('staff')->check())->toBeFalse()
        ->and($customer->fresh()->last_login_at)->not->toBeNull();
});

test('社内メンバーは社内用ログイン画面からログインできる', function () {
    $staff = User::factory()->staff()->create();

    $code = requestLoginCode('staff', $staff->email);
    $this->post('/staff/login/code', ['code' => $code])->assertRedirect('/staff');

    expect(Auth::guard('staff')->id())->toBe($staff->id)
        ->and(Auth::guard('customer')->check())->toBeFalse();
});

test('未登録・無効・ロール違いのメールアドレスにはコードを送らないが、画面上は同じ反応をする', function (string $portal, Closure $makeUser) {
    $email = $makeUser();
    $prefix = $portal === 'staff' ? '/staff' : '';

    $this->post($prefix.'/login', ['email' => $email])
        ->assertRedirect($prefix.'/login/code')
        ->assertSessionHas('status');

    Mail::assertNothingSent();
    expect(LoginCode::count())->toBe(0);
})->with([
    '未登録' => ['customer', fn () => 'nobody@example.com'],
    '無効なお客様' => ['customer', fn () => User::factory()->customer()->inactive()->create()->email],
    'お客様が社内用画面から' => ['staff', fn () => User::factory()->customer()->create()->email],
    '社内メンバーがお客様用画面から' => ['customer', fn () => User::factory()->staff()->create()->email],
]);

test('誤ったコードではログインできず、5回間違えるとそのコードは使えなくなる', function () {
    User::factory()->customer()->create(['email' => 'c@example.com']);
    $code = requestLoginCode('customer', 'c@example.com');
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login/code', ['code' => $wrong])->assertSessionHasErrors('code');
    }

    // 正しいコードでも、上限に達した後は無効
    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    expect(Auth::guard('customer')->check())->toBeFalse();
});

test('有効期限(10分)を過ぎたコードではログインできない', function () {
    User::factory()->customer()->create(['email' => 'c@example.com']);
    $code = requestLoginCode('customer', 'c@example.com');

    $this->travel(11)->minutes();

    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    expect(Auth::guard('customer')->check())->toBeFalse();
});

test('コードを再発行すると古いコードは使えなくなる', function () {
    User::factory()->customer()->create(['email' => 'c@example.com']);
    $old = requestLoginCode('customer', 'c@example.com');

    Mail::fake();
    // 遅れて届いた古いメールのコードを入力しないよう、画面とメールで案内する
    $this->post('/login/resend')
        ->assertSessionHas('status', fn (string $status) => str_contains($status, '以前のコードは使えなくなりました'));
    $new = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$new) {
        $new = $mail->code;

        return str_contains($mail->render(), '最も新しいメールのコードを入力してください');
    });

    if ($old !== $new) {
        $this->post('/login/code', ['code' => $old])->assertSessionHasErrors('code');
    }
    $this->post('/login/code', ['code' => $new])->assertRedirect('/');
});

test('メールアドレス未入力でコード入力画面を開くとログイン画面に戻される', function () {
    $this->get('/login/code')->assertRedirect('/login');
    $this->get('/staff/login/code')->assertRedirect('/staff/login');
});

test('未ログインで各ポータルを開くと、それぞれのログイン画面へ誘導される', function () {
    $this->get('/')->assertRedirect('/login');
    $this->get('/staff')->assertRedirect('/staff/login');
    $this->get('/staff/projects')->assertRedirect('/staff/login');
});

test('ログイン前に開こうとした画面に、ログイン後に戻る', function () {
    $customer = User::factory()->customer()->create();

    $this->get('/projects/ABC/issues')->assertRedirect('/login');
    $code = requestLoginCode('customer', $customer->email);

    $this->post('/login/code', ['code' => $code])->assertRedirect('/projects/ABC/issues');
});

test('同じブラウザで社内・お客様の両方に同時ログインでき、片方のログアウトはもう片方に影響しない', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();

    $staffCode = requestLoginCode('staff', $staff->email);
    $this->post('/staff/login/code', ['code' => $staffCode])->assertRedirect('/staff');

    Mail::fake();
    $customerCode = requestLoginCode('customer', $customer->email);
    $this->post('/login/code', ['code' => $customerCode])->assertRedirect('/');

    expect(Auth::guard('staff')->id())->toBe($staff->id)
        ->and(Auth::guard('customer')->id())->toBe($customer->id);

    // お客様側だけログアウト
    $this->post('/logout')->assertRedirect('/login');

    // ガードのキャッシュを捨てて、セッションの状態から判定し直す
    Auth::forgetGuards();
    expect(Auth::guard('customer')->check())->toBeFalse()
        ->and(Auth::guard('staff')->id())->toBe($staff->id);
    $this->get('/staff')->assertOk();
    $this->get('/')->assertRedirect('/login');
});

test('ログイン中に無効化されたユーザーは、次の操作でそのポータルだけログアウトされる', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $this->actingAs($staff, 'staff')->actingAs($customer, 'customer');

    $customer->update(['is_active' => false]);

    $this->get('/')->assertRedirect('/login');
    expect(Auth::guard('customer')->check())->toBeFalse();
    $this->get('/staff')->assertOk();
});

test('お客様のログイン状態では社内用画面は開けない', function () {
    $customer = User::factory()->customer()->create();
    loginAs($customer);

    $this->get('/staff')->assertRedirect('/staff/login');
    $this->get('/staff/users')->assertRedirect('/staff/login');
});

test('コードを再発行しても、1アカウントあたりの入力ミスは通算10回で一時的にロックされる', function () {
    User::factory()->customer()->create(['email' => 'c@example.com']);

    // 再送を挟みながら合計10回間違える(1つのコードあたりの上限5回には達しない)
    // (IP単位の1分あたりの制限に掛からないよう、1回ごとに時間を進める)
    for ($round = 0; $round < 4; $round++) {
        $this->travel(2)->minutes();
        Mail::fake();
        $code = requestLoginCode('customer', 'c@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < ($round < 3 ? 3 : 1); $i++) {
            $this->post('/login/code', ['code' => $wrong])->assertSessionHasErrors('code');
        }
    }

    // 正しい最新のコードでもログインできず、ロック中のメッセージになる
    $this->travel(2)->minutes();
    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors(['code' => '認証コードの入力ミスが続いたため、一時的にログインできません。時間をおいてから再度お試しいただくか、担当者にロックの解除を依頼してください。']);
    expect(Auth::guard('customer')->check())->toBeFalse();

    // 1時間経てば再びログインできる
    $this->travel(61)->minutes();
    Mail::fake();
    $code = requestLoginCode('customer', 'c@example.com');
    $this->post('/login/code', ['code' => $code])->assertRedirect('/');
});

test('使用済みのコードは再利用できない', function () {
    User::factory()->customer()->create(['email' => 'c@example.com']);
    $code = requestLoginCode('customer', 'c@example.com');
    $this->post('/login/code', ['code' => $code])->assertRedirect('/');

    Auth::guard('customer')->logout();
    $this->post('/login', ['email' => 'c@example.com']);
    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
});

test('セッションの Cookie 名は、サイト名が日本語だけでも「-session」にならない', function (string $appName, string $expected) {
    // config/session.php を、指定したサイト名(APP_NAME)で読み直して確かめる
    $backup = ['server' => $_SERVER['APP_NAME'] ?? null, 'env' => $_ENV['APP_NAME'] ?? null, 'putenv' => getenv('APP_NAME')];
    try {
        $_SERVER['APP_NAME'] = $_ENV['APP_NAME'] = $appName;
        putenv('APP_NAME='.$appName);

        expect((require config_path('session.php'))['cookie'])->toBe($expected);
    } finally {
        // 元の値に戻す(他のテストに影響させない)
        if ($backup['server'] === null) {
            unset($_SERVER['APP_NAME']);
        } else {
            $_SERVER['APP_NAME'] = $backup['server'];
        }
        if ($backup['env'] === null) {
            unset($_ENV['APP_NAME']);
        } else {
            $_ENV['APP_NAME'] = $backup['env'];
        }
        putenv($backup['putenv'] === false ? 'APP_NAME' : 'APP_NAME='.$backup['putenv']);
    }
})->with([
    '日本語だけ' => ['ムシトリ', 'mushitori-session'],
    '英数字を含む' => ['Sample Tracker', 'sample-tracker-session'],
]);
