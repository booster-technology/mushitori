<?php

namespace App\Http\Requests;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\User;
use App\Services\AttachmentService;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * コメント投稿(状態などの変更を含む)の入力チェック
 */
class StoreCommentRequest extends FormRequest
{
    /**
     * 入力チェックより先に権限を確認する(閲覧できない課題は入力内容に関わらず404にして存在を知らせない)
     */
    public function authorize(): Response
    {
        return Gate::inspect('comment', $this->route('issue'));
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', Rule::enum(IssueStatus::class)],
            'priority' => ['nullable', Rule::enum(IssuePriority::class)],
            'assignee_id' => ['nullable', 'integer', $this->assigneeRule()],
            'due_date' => ['nullable', 'date'],
        ] + AttachmentService::rules();
    }

    public function attributes(): array
    {
        return [
            'body' => 'コメント',
            'status' => '状態',
            'priority' => '優先度',
            'assignee_id' => '担当者',
            'due_date' => '期限日',
            'attachments' => '添付ファイル',
            'attachments.*' => '添付ファイル',
        ];
    }

    /**
     * 担当者は有効な社内メンバーのみ。ただし現在の担当者のまま(無効化された人でも)なら許可する
     */
    private function assigneeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $issue = $this->route('issue');
            if ($issue instanceof Issue && (int) $value === $issue->assignee_id) {
                return;
            }
            if (! User::whereKey($value)->staff()->active()->exists()) {
                $fail('選択された担当者は正しくありません。');
            }
        };
    }
}
