<?php

namespace App\Mail;

use App\Models\Issue;
use App\Models\User;
use App\Support\Portal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 新しい課題が登録されたことのお知らせ
 */
class IssueCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Issue $issue,
        public User $actor,
        public User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【'.config('app.name').'】['.$this->issue->issueKey().'] 新しい課題: '.$this->issue->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.issue-created',
            with: [
                // 受信者のロールに合わせて社内用/お客様用のURLにする
                'url' => Portal::routeFor($this->recipient, 'issues.show', $this->issue),
            ],
        );
    }
}
