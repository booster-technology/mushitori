<?php

namespace App\Services;

use App\Mail\AccountCreatedMail;
use App\Mail\IssueCommentedMail;
use App\Mail\IssueCreatedMail;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 課題の起票・コメント時のメール通知を担当する。
 * 通知の失敗で課題登録などの本処理が失敗しないよう、送信エラーはログに残して握りつぶす。
 */
class NotificationService
{
    /**
     * 新規課題の通知: プロジェクトの社内メンバー + 担当者 + 起票者(操作した本人は除く)。
     * 起票者に届くのは、社内メンバーがお客様の代わりに登録した場合(代理起票)だけ
     */
    public function issueCreated(Issue $issue, User $actor): void
    {
        $recipients = $this->staffWatchers($issue->project)
            ->push($issue->assignee)
            ->push($issue->reporter);

        foreach ($this->finalize($recipients, $issue, $actor) as $recipient) {
            $this->send($recipient, new IssueCreatedMail($issue, $actor, $recipient));
        }
    }

    /**
     * コメント・状態変更の通知: 起票者 + 担当者 + これまでのコメント投稿者(操作した本人は除く)。
     * お客様からのコメントは担当者未設定でも社内に届くよう、プロジェクトの社内メンバーにも送る。
     */
    public function issueCommented(Issue $issue, Comment $comment, User $actor): void
    {
        $commenters = User::whereIn(
            'id',
            $issue->comments()->select('user_id')->distinct()
        )->get();

        $recipients = collect([$issue->reporter, $issue->assignee])->merge($commenters);
        if ($actor->isCustomer()) {
            $recipients = $recipients->merge($this->staffWatchers($issue->project));
        }

        foreach ($this->finalize($recipients, $issue, $actor) as $recipient) {
            $this->send($recipient, new IssueCommentedMail($issue, $comment, $actor, $recipient));
        }
    }

    /**
     * アカウント作成時のログイン案内
     */
    public function accountCreated(User $user): void
    {
        $this->send($user, new AccountCreatedMail($user));
    }

    /**
     * ログイン案内の再送(社内メンバーがユーザー編集画面から送る)。
     * 送れたかどうかを画面で伝えるため、送信エラーは握りつぶさずに呼び出し元へ返す
     */
    public function loginGuideResent(User $user): void
    {
        Mail::to($user)->send(new AccountCreatedMail($user, resent: true));
    }

    /**
     * 新規課題の通知を受け取る社内メンバー。
     * プロジェクトに社内メンバーが1人も登録されていない場合は、通知漏れを防ぐため社内全員に送る
     *
     * @return Collection<int, User>
     */
    private function staffWatchers(Project $project): Collection
    {
        $members = $project->staffMembers()->active()->get();

        return $members->isNotEmpty()
            ? $members
            : User::staff()->active()->get();
    }

    /**
     * 宛先の重複・本人・無効ユーザー・閲覧権限のないユーザーを除外する
     *
     * @param  Collection<int, User|null>  $users
     * @return Collection<int, User>
     */
    private function finalize(Collection $users, Issue $issue, User $actor): Collection
    {
        return $users
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $actor->id)
            ->filter(fn (User $user) => $user->is_active && $user->canAccessProject($issue->project))
            ->values();
    }

    private function send(User $recipient, Mailable $mail): void
    {
        try {
            Mail::to($recipient)->send($mail);
        } catch (Throwable $e) {
            Log::error('通知メールの送信に失敗しました', [
                'recipient_id' => $recipient->id,
                'mail' => $mail::class,
                'exception' => $e,
            ]);
        }
    }
}
