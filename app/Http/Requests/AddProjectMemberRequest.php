<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * プロジェクトへのメンバー追加の入力チェック(有効なユーザーのみ追加できる)
 */
class AddProjectMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ];
    }

    public function attributes(): array
    {
        return [
            'user_id' => 'ユーザー',
        ];
    }
}
