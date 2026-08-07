<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Mime\Email;

final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public readonly string $encryptedToken;

    public readonly string $tokenFingerprint;

    public function __construct(string $token)
    {
        $this->encryptedToken = Crypt::encryptString($token);
        $this->tokenFingerprint = hash('sha256', $token);
        $this->afterCommit();
        $this->onQueue(config('email.queue'));
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $resetUrl = $this->resetUrl($notifiable);
        $viewData = [
            'displayName' => $notifiable->profile?->display_name,
            'resetUrl' => $resetUrl,
            'expiresInMinutes' => config('auth.passwords.users.expire'),
        ];

        return (new MailMessage)
            ->subject('Tilbakestill passordet ditt | Uncovr')
            ->view('mail.auth.reset-password', $viewData)
            ->text('mail.auth.reset-password-text', $viewData)
            ->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name'))
            ->tag('password-reset')
            ->withSymfonyMessage(function (Email $message) use ($notifiable): void {
                $message->getHeaders()->addTextHeader(
                    'X-Uncovr-Resend-Idempotency-Key',
                    'password-reset-'.hash('sha256', implode('|', [
                        $notifiable->public_id,
                        $this->tokenFingerprint,
                    ])),
                );
            });
    }

    private function resetUrl(User $user): string
    {
        $query = http_build_query([
            'token' => Crypt::decryptString($this->encryptedToken),
            'email' => $user->email,
        ], encoding_type: PHP_QUERY_RFC3986);

        return rtrim(config('authentication.portal_url'), '/')
            .'/reset-password?'.$query;
    }
}
