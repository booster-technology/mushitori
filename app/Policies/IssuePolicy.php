<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * 課題の権限
 * - 閲覧・コメント: プロジェクトを閲覧できる人
 * - 編集(件名・詳細・添付): 社内メンバー、または起票した本人
 * - 担当者・優先度・期限日の変更、削除: 社内メンバーのみ
 */
class IssuePolicy
{
    public function view(User $user, Issue $issue): Response
    {
        return $user->canAccessProject($issue->project)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function comment(User $user, Issue $issue): Response
    {
        return $this->view($user, $issue);
    }

    public function update(User $user, Issue $issue): Response
    {
        if (! $user->canAccessProject($issue->project)) {
            return Response::denyAsNotFound();
        }

        return $user->isStaff() || $issue->reporter_id === $user->id
            ? Response::allow()
            : Response::deny();
    }

    /**
     * 担当者・優先度・期限日を変更できるか
     */
    public function manage(User $user, Issue $issue): bool
    {
        return $user->isStaff();
    }

    /**
     * 削除は社内メンバーのみ。見られないプロジェクトの課題は、存在自体を知らせないよう404にする
     */
    public function delete(User $user, Issue $issue): Response
    {
        if (! $user->canAccessProject($issue->project)) {
            return Response::denyAsNotFound();
        }

        return $user->isStaff() ? Response::allow() : Response::deny();
    }
}
