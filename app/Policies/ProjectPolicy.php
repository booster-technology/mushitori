<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * プロジェクトの権限。閲覧できないプロジェクトは存在自体を知らせないよう404にする
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): Response
    {
        return $user->canAccessProject($project)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * 課題の起票はプロジェクトを閲覧できる人なら誰でも可
     */
    public function createIssue(User $user, Project $project): Response
    {
        return $this->view($user, $project);
    }

    /**
     * プロジェクトの作成・編集・メンバー管理は社内メンバーのみ
     */
    public function manage(User $user): bool
    {
        return $user->isStaff();
    }
}
