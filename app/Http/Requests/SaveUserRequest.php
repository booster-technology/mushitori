<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ユーザーの作成・編集フォームの入力チェック(社内メンバー専用画面)
 */
class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 社内用ルートグループ(auth:staff)でのみ使うため、ここでは追加の権限チェックはしない
        return true;
    }

    /**
     * メールアドレスは小文字に揃えてからチェックする(大文字小文字違いの重複登録を防ぐ)
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user instanceof User ? $user->id : null)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'company_name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'projects' => ['nullable', 'array'],
            'projects.*' => ['integer', Rule::exists('projects', 'id')],
            'send_welcome_mail' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '氏名',
            'email' => 'メールアドレス',
            'role' => '種別',
            'company_name' => '会社名',
            'is_active' => '状態',
            'projects' => '参加プロジェクト',
        ];
    }
}
