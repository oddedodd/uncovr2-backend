<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

final class AuthSmokeTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $runId) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [config('mail.reply_to.address')],
            subject: 'Uncovr – kontrollert test av e-postlevering',
            tags: ['auth-smoke-test'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.auth.smoke-test',
            text: 'mail.auth.smoke-test-text',
            with: [
                'runId' => $this->runId,
                'sentAt' => now()->utc()->format('Y-m-d H:i:s').' UTC',
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-Uncovr-Resend-Idempotency-Key' => 'auth-smoke-'.$this->runId,
        ]);
    }
}
