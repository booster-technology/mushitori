<?php

namespace App\Http\Requests;

use App\Enums\IssueType;
use App\Services\AttachmentService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * 課題の編集フォームの入力チェック
 */
class UpdateIssueRequest extends FormRequest
{
    /**
     * 入力チェックより先に権限を確認する
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('issue'));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::enum(IssueType::class)],
            'description' => ['nullable', 'string', 'max:20000'],
            'remove_attachments' => ['nullable', 'array'],
            'remove_attachments.*' => ['integer'],
        ] + AttachmentService::rules();
    }

    public function attributes(): array
    {
        return [
            'title' => '件名',
            'type' => '種別',
            'description' => '詳細',
            'attachments' => '添付ファイル',
            'attachments.*' => '添付ファイル',
        ];
    }
}
