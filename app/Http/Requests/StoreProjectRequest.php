<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * プロジェクト作成フォームの入力チェック(社内メンバー専用画面)
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 社内用ルートグループ(auth:staff)でのみ使うため、ここでは追加の権限チェックはしない
        return true;
    }

    /**
     * プロジェクトキーは大文字に揃えてからチェックする
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => strtoupper(trim((string) $this->input('key')))]);
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,9}$/', Rule::unique('projects', 'key')],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'members' => ['nullable', 'array'],
            'members.*' => ['integer', Rule::exists('users', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'プロジェクトキーは英大文字で始まる2〜10文字の英大文字・数字・アンダースコアで入力してください。',
        ];
    }

    public function attributes(): array
    {
        return [
            'key' => 'プロジェクトキー',
            'name' => 'プロジェクト名',
            'description' => '説明',
            'members' => 'メンバー',
        ];
    }
}
