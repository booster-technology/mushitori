<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * プロジェクト編集フォームの入力チェック(プロジェクトキーは変更不可なので受け付けない)
 */
class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'プロジェクト名',
            'description' => '説明',
        ];
    }
}
