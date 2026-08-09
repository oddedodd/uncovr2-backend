<?php

namespace Tests\Feature\Operations;

use App\Models\EmailDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ResendWebhookTest extends TestCase
{
    private const SECRET_BYTES = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'email.webhook.secret' => 'whsec_'.base64_encode(self::SECRET_BYTES),
            'email.webhook.tolerance_seconds' => 300,
        ]);
    }

    public function test_valid_signed_event_is_stored_without_email_content_or_recipient_data(): void
    {
        $payload = $this->payload('email.sent', 'provider-message-1');

        $this->signedPost('svix-valid-1', $payload)
            ->assertOk()
            ->assertExactJson(['received' => true, 'duplicate' => false]);

        $this->assertDatabaseHas('email_deliveries', [
            'provider_message_id' => 'provider-message-1',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('email_webhook_events', [
            'svix_id' => 'svix-valid-1',
            'event_type' => 'email.sent',
        ]);

        $deliveryColumns = Schema::getColumnListing('email_deliveries');
        $eventColumns = Schema::getColumnListing('email_webhook_events');

        foreach (['recipient', 'email', 'subject', 'body', 'payload', 'headers'] as $forbidden) {
            $this->assertNotContains($forbidden, $deliveryColumns);
            $this->assertNotContains($forbidden, $eventColumns);
        }
    }

    public function test_invalid_missing_and_expired_signatures_are_rejected(): void
    {
        $payload = $this->payload('email.sent', 'provider-message-2');
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/webhooks/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertBadRequest();

        $this->signedPost('svix-invalid', $payload, signature: 'v1,invalid')
            ->assertBadRequest();

        $this->signedPost('svix-expired', $payload, timestamp: time() - 301)
            ->assertBadRequest();

        $this->assertDatabaseCount('email_webhook_events', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_duplicate_and_replayed_event_is_acknowledged_once(): void
    {
        $payload = $this->payload('email.delivered', 'provider-message-3');
        $timestamp = time();

        $this->signedPost('svix-duplicate', $payload, $timestamp)->assertJsonPath('duplicate', false);
        $this->signedPost('svix-duplicate', $payload, $timestamp)->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('email_webhook_events', 1);
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    public function test_out_of_order_events_never_regress_delivery_state(): void
    {
        $messageId = 'provider-message-4';
        $base = CarbonImmutable::now()->startOfSecond();

        $this->signedPost('svix-delivered', $this->payload('email.delivered', $messageId, $base->addSeconds(20)));
        $this->signedPost('svix-sent-late', $this->payload('email.sent', $messageId, $base->addSeconds(10)));
        $this->assertSame('delivered', EmailDelivery::query()->sole()->status);

        $this->signedPost('svix-bounced', $this->payload('email.bounced', $messageId, $base->addSeconds(30)));
        $this->assertSame('bounced', EmailDelivery::query()->sole()->status);

        $this->signedPost('svix-complained', $this->payload('email.complained', $messageId, $base->addSeconds(40)));
        $this->signedPost('svix-failed-newer', $this->payload('email.failed', $messageId, $base->addSeconds(50)));

        $delivery = EmailDelivery::query()->sole();
        $this->assertSame('complained', $delivery->status);
        $this->assertSame($base->addSeconds(40)->toISOString(), $delivery->last_event_at->toISOString());
        $this->assertDatabaseCount('email_webhook_events', 5);
    }

    public function test_all_supported_delivery_states_are_processed(): void
    {
        $events = [
            'email.sent' => 'sent',
            'email.delivery_delayed' => 'delivery_delayed',
            'email.delivered' => 'delivered',
            'email.bounced' => 'bounced',
            'email.complained' => 'complained',
            'email.suppressed' => 'suppressed',
            'email.failed' => 'failed',
        ];

        foreach ($events as $event => $status) {
            $messageId = 'provider-'.str_replace(['email.', '_'], ['', '-'], $event);
            $this->signedPost('svix-'.$status, $this->payload($event, $messageId))->assertOk();
            $this->assertDatabaseHas('email_deliveries', [
                'provider_message_id' => $messageId,
                'status' => $status,
            ]);
        }
    }

    public function test_signed_unsupported_or_malformed_payload_is_rejected(): void
    {
        $this->signedPost('svix-opened', $this->payload('email.opened', 'provider-opened'))
            ->assertBadRequest();
        $this->signedPost('svix-missing-id', [
            'type' => 'email.sent',
            'created_at' => now()->toISOString(),
            'data' => [],
        ])->assertBadRequest();

        $this->assertDatabaseCount('email_webhook_events', 0);
    }

    public function test_oversized_payload_is_rejected_before_signature_processing(): void
    {
        config(['email.webhook.max_payload_bytes' => 10]);

        $this->signedPost('svix-large', $this->payload('email.sent', 'provider-large'))
            ->assertStatus(413);
    }

    /** @return array<string, mixed> */
    private function payload(string $event, string $messageId, mixed $occurredAt = null): array
    {
        return [
            'type' => $event,
            'created_at' => ($occurredAt ?? now())->toISOString(),
            'data' => [
                'email_id' => $messageId,
                'to' => ['private-recipient@example.com'],
                'subject' => 'Private subject',
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function signedPost(
        string $svixId,
        array $payload,
        ?int $timestamp = null,
        ?string $signature = null,
    ): TestResponse {
        $timestamp ??= time();
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature ??= 'v1,'.base64_encode(hash_hmac(
            'sha256',
            "{$svixId}.{$timestamp}.{$raw}",
            self::SECRET_BYTES,
            true,
        ));

        return $this->call('POST', '/api/v1/webhooks/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SVIX_ID' => $svixId,
            'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_SVIX_SIGNATURE' => $signature,
        ], $raw);
    }
}
