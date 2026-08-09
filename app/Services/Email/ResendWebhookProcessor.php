<?php

namespace App\Services\Email;

use App\Models\EmailDelivery;
use App\Models\EmailWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class ResendWebhookProcessor
{
    private const EVENT_STATUSES = [
        'email.sent' => 'sent',
        'email.delivery_delayed' => 'delivery_delayed',
        'email.delivered' => 'delivered',
        'email.bounced' => 'bounced',
        'email.complained' => 'complained',
        'email.suppressed' => 'suppressed',
        'email.failed' => 'failed',
    ];

    private const STATUS_RANK = [
        'sent' => 10,
        'delivery_delayed' => 20,
        'delivered' => 30,
        'bounced' => 40,
        'suppressed' => 40,
        'failed' => 40,
        'complained' => 50,
    ];

    private const TERMINAL_STATUSES = [
        'delivered', 'bounced', 'complained', 'suppressed', 'failed',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return bool True when stored, false when the svix ID was already processed.
     */
    public function process(string $svixId, array $payload): bool
    {
        [$eventType, $status, $messageId, $occurredAt] = $this->validated($svixId, $payload);

        try {
            return DB::transaction(function () use ($svixId, $eventType, $status, $messageId, $occurredAt): bool {
                if (EmailWebhookEvent::query()->where('svix_id', $svixId)->exists()) {
                    return false;
                }

                DB::table('email_deliveries')->insertOrIgnore([
                    'provider_message_id' => $messageId,
                    'status' => $status,
                    'last_event_at' => $occurredAt,
                    'terminal_at' => $this->terminalAt($status, $occurredAt),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $delivery = EmailDelivery::query()
                    ->where('provider_message_id', $messageId)
                    ->lockForUpdate()
                    ->firstOrFail();

                EmailWebhookEvent::query()->create([
                    'svix_id' => $svixId,
                    'email_delivery_id' => $delivery->getKey(),
                    'event_type' => $eventType,
                    'event_occurred_at' => $occurredAt,
                    'processed_at' => now(),
                ]);

                if ($this->shouldAdvance($delivery, $status, $occurredAt)) {
                    $delivery->update([
                        'status' => $status,
                        'last_event_at' => $occurredAt,
                        'terminal_at' => $this->terminalAt($status, $occurredAt),
                    ]);
                }

                return true;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** @param array<string, mixed> $payload */
    private function validated(string $svixId, array $payload): array
    {
        if ($svixId === '' || strlen($svixId) > 150) {
            throw new InvalidArgumentException('Invalid webhook event ID.');
        }

        $eventType = $payload['type'] ?? null;
        $status = is_string($eventType) ? (self::EVENT_STATUSES[$eventType] ?? null) : null;
        $messageId = data_get($payload, 'data.email_id');
        $createdAt = $payload['created_at'] ?? null;

        if ($status === null || ! is_string($eventType)) {
            throw new InvalidArgumentException('Unsupported webhook event type.');
        }

        if (! is_string($messageId) || $messageId === '' || strlen($messageId) > 100) {
            throw new InvalidArgumentException('Invalid provider message ID.');
        }

        if (! is_string($createdAt)) {
            throw new InvalidArgumentException('Invalid event timestamp.');
        }

        try {
            $occurredAt = CarbonImmutable::parse($createdAt)->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid event timestamp.');
        }

        return [$eventType, $status, $messageId, $occurredAt];
    }

    private function shouldAdvance(EmailDelivery $delivery, string $status, CarbonImmutable $occurredAt): bool
    {
        $currentRank = self::STATUS_RANK[$delivery->status];
        $nextRank = self::STATUS_RANK[$status];

        return $nextRank > $currentRank
            || ($nextRank === $currentRank && $occurredAt->isAfter($delivery->last_event_at));
    }

    private function terminalAt(string $status, CarbonImmutable $occurredAt): ?CarbonImmutable
    {
        return in_array($status, self::TERMINAL_STATUSES, true) ? $occurredAt : null;
    }
}
