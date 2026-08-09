<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Email\ResendWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Resend\WebhookSignature;
use Throwable;

final class ResendWebhookController extends Controller
{
    public function __invoke(Request $request, ResendWebhookProcessor $processor): JsonResponse
    {
        $rawPayload = $request->getContent();

        if (strlen($rawPayload) > config('email.webhook.max_payload_bytes')) {
            return response()->json(['received' => false], 413);
        }

        $secret = config('email.webhook.secret');

        if (! is_string($secret) || $secret === '') {
            Log::critical('Resend webhook secret is not configured.', ['event' => 'email.webhook_configuration_error']);

            return response()->json(['received' => false], 503);
        }

        $headers = [
            'svix-id' => (string) $request->header('svix-id', ''),
            'svix-timestamp' => (string) $request->header('svix-timestamp', ''),
            'svix-signature' => (string) $request->header('svix-signature', ''),
        ];

        try {
            WebhookSignature::verify(
                $rawPayload,
                $headers,
                $secret,
                config('email.webhook.tolerance_seconds'),
            );
        } catch (Throwable) {
            Log::notice('Rejected invalid Resend webhook signature.', ['event' => 'email.webhook_rejected']);

            return response()->json(['received' => false], 400);
        }

        try {
            $payload = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                throw new InvalidArgumentException('Webhook payload must be an object.');
            }

            $stored = $processor->process($headers['svix-id'], $payload);
        } catch (JsonException|InvalidArgumentException) {
            Log::warning('Rejected malformed signed Resend webhook.', ['event' => 'email.webhook_malformed']);

            return response()->json(['received' => false], 400);
        }

        return response()->json(['received' => true, 'duplicate' => ! $stored]);
    }
}
