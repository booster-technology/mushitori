<?php

namespace App\Mail;

use App\Models\User;
use App\Services\LoginCodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ログイン用の6桁認証コードのお知らせ
 */
class LoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【'.config('app.name').'】ログイン認証コードのお知らせ',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.login-code',
            with: ['expiresMinutes' => LoginCodeService::EXPIRES_MINUTES],
        );
    }
}
