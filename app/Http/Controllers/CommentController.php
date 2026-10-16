<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Models\Issue;
use App\Services\CommentService;
use App\Support\Portal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * コメントの投稿・削除(社内/お客様の両ポータル共通)
 */
class CommentController extends Controller
{
    public function __construct(
        private readonly CommentService $comments,
    ) {}

    /**
     * コメントを投稿する(状態・担当者などの変更も同時に行える)
     */
    public function store(StoreCommentRequest $request, Issue $issue): RedirectResponse
    {
        Gate::authorize('comment', $issue);

        try {
            $comment = $this->comments->add(
                $issue,
                $request->user(),
                $request->validated(),
                $request->file('attachments', [])
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->failed($e, 'コメントの投稿に失敗しました');
        }

        return redirect()->to(Portal::route('issues.show', $issue).'#comment-'.$comment->id)
            ->with('success', 'コメントを投稿しました。');
    }

    /**
     * コメントを削除する(投稿者本人か社内メンバー)
     */
    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        $issue = $comment->issue;

        try {
            $this->comments->delete($comment, $request->user());
        } catch (Throwable $e) {
            return $this->failed($e, 'コメントの削除に失敗しました');
        }

        return redirect()->to(Portal::route('issues.show', $issue))
            ->with('success', 'コメントを削除しました。');
    }
}
