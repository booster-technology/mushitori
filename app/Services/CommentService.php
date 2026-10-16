<?php

namespace App\Services;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * コメント投稿(と同時に行う状態・担当者などの変更)を担当する
 */
class CommentService
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * コメントを投稿し、状態などの変更があれば課題に反映して変更履歴として記録する
     *
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     */
    public function add(Issue $issue, User $actor, array $data, array $files = []): Comment
    {
        [$updates, $changeLog] = $this->detectChanges($issue, $actor, $data);
        $body = trim((string) ($data['body'] ?? ''));

        if ($body === '' && $changeLog === [] && $files === []) {
            throw ValidationException::withMessages([
                'body' => 'コメントを入力するか、状態などを変更してください。',
            ]);
        }

        /** @var list<Attachment> $stored */
        $stored = [];

        try {
            $comment = DB::transaction(function () use ($issue, $actor, $body, $updates, $changeLog, $files, &$stored) {
                $comment = Comment::create([
                    'issue_id' => $issue->id,
                    'user_id' => $actor->id,
                    'body' => $body === '' ? null : $body,
                    'change_log' => $changeLog === [] ? null : $changeLog,
                ]);

                foreach ($files as $file) {
                    $stored[] = $this->attachments->store($file, $issue, $actor, $comment);
                }

                // 変更が無くても、コメントがあったことを一覧の「更新日時」に反映する
                $issue->fill($updates);
                $issue->updated_at = now();
                $issue->save();

                return $comment;
            });
        } catch (Throwable $e) {
            foreach ($stored as $attachment) {
                $this->attachments->deleteFiles($attachment);
            }
            throw $e;
        }

        $issue->load(['project', 'reporter', 'assignee']);
        $this->notifications->issueCommented($issue, $comment, $actor);

        return $comment;
    }

    /**
     * コメントを削除する(論理削除)。本文・添付ファイルのデータは残し、画面では「削除されました」と変更履歴だけを表示する。
     * コメントの添付ファイルも一緒に論理削除する(コメントを復旧するときは添付も一緒に戻す)
     */
    public function delete(Comment $comment, User $actor): void
    {
        DB::transaction(function () use ($comment, $actor) {
            $comment->attachments()->get()->each(fn (Attachment $a) => $this->attachments->delete($a, $actor));
            $comment->trashBy($actor);
        });
    }

    /**
     * フォームの値と現在の課題を比べて、更新内容と変更履歴を作る。
     * お客様が変更できるのは状態のみ。担当者・優先度・期限日は社内メンバーのみ変更できる
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<array{field: string, from: mixed, to: mixed}>}
     */
    private function detectChanges(Issue $issue, User $actor, array $data): array
    {
        $updates = [];
        $changeLog = [];

        if (! empty($data['status']) && $data['status'] !== $issue->status->value) {
            $newStatus = IssueStatus::from($data['status']);
            $updates['status'] = $newStatus;
            $updates['closed_at'] = $newStatus === IssueStatus::Closed ? now() : null;
            $changeLog[] = ['field' => 'status', 'from' => $issue->status->value, 'to' => $newStatus->value];
        }

        if (! $actor->isStaff()) {
            return [$updates, $changeLog];
        }

        if (array_key_exists('assignee_id', $data)) {
            $newAssigneeId = $data['assignee_id'] ? (int) $data['assignee_id'] : null;
            if ($newAssigneeId !== $issue->assignee_id) {
                $updates['assignee_id'] = $newAssigneeId;
                // 担当者は名前を記録しておく(後で名前が変わっても当時の表示を残すため)
                $changeLog[] = [
                    'field' => 'assignee',
                    'from' => $issue->assignee?->name,
                    'to' => $newAssigneeId ? User::find($newAssigneeId)?->name : null,
                ];
            }
        }

        if (! empty($data['priority']) && $data['priority'] !== $issue->priority->value) {
            $updates['priority'] = IssuePriority::from($data['priority']);
            $changeLog[] = ['field' => 'priority', 'from' => $issue->priority->value, 'to' => $data['priority']];
        }

        if (array_key_exists('due_date', $data)) {
            $newDueDate = $data['due_date'] ?: null;
            $currentDueDate = $issue->due_date?->format('Y-m-d');
            if ($newDueDate !== $currentDueDate) {
                $updates['due_date'] = $newDueDate;
                $changeLog[] = ['field' => 'due_date', 'from' => $currentDueDate, 'to' => $newDueDate];
            }
        }

        return [$updates, $changeLog];
    }
}
