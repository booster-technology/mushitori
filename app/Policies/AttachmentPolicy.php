<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * 添付ファイルの権限。課題を閲覧できる人のみ表示・ダウンロードできる
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): Response
    {
        $issue = $attachment->issue;

        // 削除済みの課題・コメントに付いていた添付は見せない
        if ($issue === null || $issue->project === null
            || ($attachment->comment_id !== null && $attachment->comment === null)) {
            return Response::denyAsNotFound();
        }

        return $user->canAccessProject($issue->project)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
