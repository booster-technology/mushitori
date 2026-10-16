<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\LoginCodeService;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

/**
 * 社内メンバーをコマンドで追加・復旧する(全員が無効化されて誰もログインできなくなった場合などに使う)
 */
class CreateStaffCommand extends Command
{
    protected $signature = 'app:create-staff {email : メールアドレス} {--name= : 氏名(省略時はメールアドレスの@より前)}';

    protected $description = '社内メンバーを追加する(既存ユーザーなら社内メンバーとして有効化する)';

    public function handle(LoginCodeService $loginCodes): int
    {
        $email = LoginCodeService::normalizeEmail((string) $this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('メールアドレスの形式が正しくありません。');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);

        // お客様を誤って社内メンバーにしないよう確認する(ロックの解除だけなら app:unlock-login を使う)
        if ($user->exists && $user->role === UserRole::Customer
            && ! confirm("{$email} はお客様として登録されています。社内メンバーに変更しますか？（全プロジェクトの課題を見られるようになります）", default: false, yes: 'はい', no: 'いいえ')) {
            $this->warn('中止しました。ログインのロックを解除するだけなら php artisan app:unlock-login '.$email.' を実行してください。');

            return self::FAILURE;
        }

        $user->fill([
            'name' => $this->option('name') ?: ($user->name ?? strstr($email, '@', true)),
            'role' => UserRole::Staff->value,
            'company_name' => null,
            'is_active' => true,
        ])->save();

        // 認証コードの入力ミスで一時ロックされていれば解除する(第三者にわざと入力ミスを繰り返された場合の復旧用)
        $loginCodes->unlock($user);

        $this->info("社内メンバー「{$user->name}」<{$user->email}> を".($user->wasRecentlyCreated ? '追加' : '有効化').'しました。');

        return self::SUCCESS;
    }
}
