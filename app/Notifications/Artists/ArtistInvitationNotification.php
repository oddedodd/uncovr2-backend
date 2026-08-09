<?php

namespace App\Notifications\Artists;

use App\Models\ArtistInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

final class ArtistInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $plainToken)
    {
        $this->afterCommit();
        $this->onQueue(config('email.queue'));
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(ArtistInvitation $notifiable): MailMessage
    {
        $notifiable->loadMissing('artist.profile', 'invitedBy.profile');
        $url = rtrim(config('authentication.portal_url'), '/').'/artist-invitations/accept?token='.urlencode($this->plainToken);
        $idempotencyKey = 'artist-invite-'.hash('sha256', $notifiable->public_id.'|'.$notifiable->send_count);
        $data = [
            'artistName' => $notifiable->artist->profile->name,
            'inviterName' => $notifiable->invitedBy?->profile?->display_name,
            'acceptUrl' => $url,
            'expiresAt' => $notifiable->expires_at,
        ];

        return (new MailMessage)
            ->subject('Invitasjon til '.$notifiable->artist->profile->name.' | Uncovr')
            ->view('mail.artists.invitation', $data)
            ->text('mail.artists.invitation-text', $data)
            ->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name'))
            ->tag('artist-invitation')
            ->withSymfonyMessage(fn (Email $message) => $message->getHeaders()->addTextHeader('X-Uncovr-Resend-Idempotency-Key', $idempotencyKey));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
