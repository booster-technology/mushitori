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
 * アカウント作成時のログイン案内
 */
class AccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【'.config('app.name').'】アカウントを作成しました',
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
