<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Portal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * アカウント作成時のログイン案内(社内メンバーがユーザー編集画面から再送する場合も使う)
 */
class AccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  bool  $resent  再送の場合は true(件名・見出しを「ログインのご案内」にする)
     */
    public function __construct(
        public User $user,
        public bool $resent = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【'.config('app.name').'】'.($this->resent ? 'ログインのご案内' : 'アカウントを作成しました'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.account-created',
            with: ['url' => Portal::routeFor($this->user, 'login')],
        );
    }
}
