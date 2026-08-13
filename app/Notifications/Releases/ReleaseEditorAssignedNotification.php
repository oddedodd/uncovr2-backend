<?php

namespace App\Notifications\Releases;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

final class ReleaseEditorAssignedNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /**
     * Primitives only. Queueable does not bring SerializesModels, so models
     * would be serialized whole onto the queue instead of by reference.
     */
    public function __construct(
        public readonly string $releaseId,
        public readonly string $releaseTitle,
        public readonly string $ownerName,
        public readonly ?string $assignedByName,
        public readonly int $assignmentId,
    ) {
        $this->afterCommit();
        $this->onQueue(config('email.queue'));
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = rtrim(config('authentication.portal_url'), '/').'/releases/'.$this->releaseId;
        // Keyed on the assignment row, so retries of the same grant dedupe while
        // a genuine remove-then-re-add produces a new row and a new mail.
        $idempotencyKey = 'release-editor-'.hash('sha256', (string) $this->assignmentId);
        $data = [
            'releaseTitle' => $this->releaseTitle,
            'ownerName' => $this->ownerName,
            'assignedByName' => $this->assignedByName,
            'builderUrl' => $url,
        ];

        return (new MailMessage)
            ->subject('Du kan nå redigere '.$this->releaseTitle.' | Uncovr')
            ->view('mail.releases.editor-assigned', $data)
            ->text('mail.releases.editor-assigned-text', $data)
            ->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name'))
            ->tag('release-editor-assignment')
            ->withSymfonyMessage(fn (Email $message) => $message->getHeaders()->addTextHeader('X-Uncovr-Resend-Idempotency-Key', $idempotencyKey));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
