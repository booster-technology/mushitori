<?php

namespace App\Http\Requests;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\UserRole;
use App\Services\AttachmentService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * 課題の新規登録フォームの入力チェック
 */
class StoreIssueRequest extends FormRequest
{
    /**
     * 入力チェックより先に権限を確認する(閲覧できないプロジェクトは入力内容に関わらず404にして存在を知らせない)
     */
    public function authorize(): Response
    {
        return Gate::inspect('createIssue', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::enum(IssueType::class)],
            'priority' => ['required', Rule::enum(IssuePriority::class)],
            'description' => ['nullable', 'string', 'max:20000'],
            // 担当者は有効な社内メンバーのみ(お客様の入力値はサービス側で無視する)
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')
                ->where('role', UserRole::Staff->value)->where('is_active', true)],
            'due_date' => ['nullable', 'date'],
        ] + AttachmentService::rules();
    }

    public function attributes(): array
    {
        return [
            'title' => '件名',
            'type' => '種別',
            'priority' => '優先度',
            'description' => '詳細',
            'assignee_id' => '担当者',
            'due_date' => '期限日',
            'attachments' => '添付ファイル',
            'attachments.*' => '添付ファイル',
        ];
    }
}
