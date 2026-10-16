<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LoginCodeService;
use Illuminate\Console\Command;

/**
 * 認証コードの入力ミスによるログインのロックをコマンドで解除する。
 * 通常は社内メンバーがユーザー管理画面から解除するが、社内メンバー全員がロックされて画面を開けない場合などに使う。
 * ロール・有効/無効などのユーザー情報は変更しない
 */
class UnlockLoginCommand extends Command
{
    protected $signature = 'app:unlock-login {email : ロックを解除するユーザーのメールアドレス}';

    protected $description = '認証コードの入力ミスによるログインのロックを解除する(ユーザー情報は変更しない)';

    public function handle(LoginCodeService $loginCodes): int
    {
        $email = LoginCodeService::normalizeEmail((string) $this->argument('email'));
        $user = User::where('email', $email)->first();
        if ($user === null) {
            $this->error("{$email} のユーザーは登録されていません。");

            return self::FAILURE;
        }

        $wasLocked = $loginCodes->lockedMinutes($user) !== null;
        $loginCodes->unlock($user);

        $this->info("「{$user->name}」<{$user->email}>（{$user->role->label()}）のログインのロックを解除しました。"
            .($wasLocked ? '' : '（ロックはされていませんでした。認証コードの送信回数の制限はリセットしました）'));
        if (! $user->is_active) {
            $this->warn('このユーザーは「無効」のため、ログインできません。ユーザー管理画面で「有効」にしてください。');
        }

        return self::SUCCESS;
    }
}
