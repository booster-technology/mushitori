<?php

namespace App\Mail;

use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Support\ChangeLogFormatter;
use App\Support\Portal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 課題にコメント・状態変更があったことのお知らせ
 */
class IssueCommentedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Issue $issue,
        public Comment $comment,
        public User $actor,
        public User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【'.config('app.name').'】['.$this->issue->issueKey().'] '.$this->issue->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.issue-commented',
            with: [
                'url' => Portal::routeFor($this->recipient, 'issues.show', $this->issue),
                'changes' => ChangeLogFormatter::lines($this->comment->change_log ?? []),
            ],
        );
    }
}
