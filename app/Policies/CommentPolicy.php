<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * コメントの権限。削除できるのは社内メンバーか投稿した本人
 * (見られないプロジェクトのコメントは、存在自体を知らせないよう404にする)
 */
class CommentPolicy
{
    public function delete(User $user, Comment $comment): Response
    {
        $project = $comment->issue?->project;
        if ($project === null || ! $user->canAccessProject($project)) {
            return Response::denyAsNotFound();
        }

        return $user->isStaff() || $comment->user_id === $user->id
            ? Response::allow()
            : Response::deny();
    }
}
