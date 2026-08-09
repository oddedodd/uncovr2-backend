<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Listeners\UpdateNotificationPreferenceRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ConsentRecord;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ListenerPreferenceController extends Controller
{
    private const TOPICS = ['artist_updates', 'release_updates', 'product_updates', 'marketing'];

    public function index(Request $request): JsonResponse
    {
        $stored = $request->user()->notificationPreferences()->get()->keyBy('topic');

        return ApiResponse::success(['preferences' => collect(self::TOPICS)->map(function (string $topic) use ($stored): array {
            $preference = $stored->get($topic);

            return ['topic' => $topic, 'email_enabled' => $preference?->email_enabled ?? false, 'push_enabled' => $preference?->push_enabled ?? false, 'in_app_enabled' => $preference?->in_app_enabled ?? true];
        })->all(), 'required_channels' => ['account_email' => true, 'security_email' => true]])->header('Cache-Control', 'private, no-store');
    }

    public function update(UpdateNotificationPreferenceRequest $request, string $topic): JsonResponse
    {
        abort_unless(in_array($topic, self::TOPICS, true), 404);
        if ($topic === 'marketing') {
            $this->assertConsent($request, 'marketing_email', $request->boolean('email_enabled'));
            $this->assertConsent($request, 'marketing_push', $request->boolean('push_enabled'));
        }
        DB::table('notification_preferences')->upsert([[
            'user_id' => $request->user()->getKey(), 'topic' => $topic,
            'email_enabled' => $request->boolean('email_enabled'), 'push_enabled' => $request->boolean('push_enabled'),
            'in_app_enabled' => $request->boolean('in_app_enabled'), 'created_at' => now(), 'updated_at' => now(),
        ]], ['user_id', 'topic'], ['email_enabled', 'push_enabled', 'in_app_enabled', 'updated_at']);
        $preference = NotificationPreference::query()->where('user_id', $request->user()->getKey())->where('topic', $topic)->sole();

        return ApiResponse::success(['topic' => $topic, 'email_enabled' => $preference->email_enabled, 'push_enabled' => $preference->push_enabled, 'in_app_enabled' => $preference->in_app_enabled]);
    }

    private function assertConsent(Request $request, string $purpose, bool $enabling): void
    {
        if (! $enabling) {
            return;
        }
        $latest = ConsentRecord::query()->where('user_id', $request->user()->getKey())->where('purpose', $purpose)->latest('recorded_at')->latest('id')->first();
        if (! $latest?->granted) {
            throw ValidationException::withMessages([$purpose => ['Current consent is required before enabling this marketing channel.']]);
        }
    }
}
