<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

final class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $version)
    {
        $this->afterCommit();
        $this->onQueue(config('email.queue'));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /** @param User $notifiable */
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);
        $idempotencyKey = $this->idempotencyKey($notifiable);
        $viewData = [
            'displayName' => $notifiable->profile?->display_name,
            'verificationUrl' => $verificationUrl,
            'expiresInMinutes' => config('auth.verification.expire'),
        ];

        return (new MailMessage)
            ->subject('Bekreft e-postadressen din | Uncovr')
            ->view('mail.auth.verify-email', $viewData)
            ->text('mail.auth.verify-email-text', $viewData)
            ->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name'))
            ->tag('email-verification')
            ->withSymfonyMessage(function (Email $message) use ($idempotencyKey): void {
                $message->getHeaders()->addTextHeader(
                    'X-Uncovr-Resend-Idempotency-Key',
                    $idempotencyKey,
                );
            });
    }

    /** @param User $notifiable */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'api.v1.auth.verify-email',
            Carbon::now()->addMinutes(config('auth.verification.expire')),
            [
                'user' => $notifiable->public_id,
                'version' => $this->version,
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }

    private function idempotencyKey(User $user): string
    {
        return 'verify-email-'.hash('sha256', implode('|', [
            $user->public_id,
            (string) $this->version,
            $user->getEmailForVerification(),
        ]));
    }
}
