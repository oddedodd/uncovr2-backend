<?php

namespace App\Notifications\Organizations;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

final class OrganizationInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $plainToken)
    {
        $this->afterCommit();
        $this->onQueue(config('email.queue'));
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(OrganizationInvitation $notifiable): MailMessage
    {
        $notifiable->loadMissing('organization.profile', 'invitedBy.profile');
        $url = rtrim(config('authentication.portal_url'), '/').'/invitations/accept?token='.urlencode($this->plainToken);
        $idempotencyKey = 'organization-invite-'.hash('sha256', $notifiable->public_id.'|'.$notifiable->send_count);
        $data = [
            'organizationName' => $notifiable->organization->profile->name,
            'inviterName' => $notifiable->invitedBy?->profile?->display_name,
            'acceptUrl' => $url,
            'expiresAt' => $notifiable->expires_at,
        ];

        return (new MailMessage)
            ->subject('Invitasjon til '.$notifiable->organization->profile->name.' | Uncovr')
            ->view('mail.organizations.invitation', $data)
            ->text('mail.organizations.invitation-text', $data)
            ->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name'))
            ->tag('organization-invitation')
            ->withSymfonyMessage(fn (Email $message) => $message->getHeaders()->addTextHeader('X-Uncovr-Resend-Idempotency-Key', $idempotencyKey));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
