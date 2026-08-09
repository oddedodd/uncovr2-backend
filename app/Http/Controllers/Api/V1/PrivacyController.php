<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Listeners\RecordConsentRequest;
use App\Http\Requests\Api\V1\Listeners\RequestAccountDeletionRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ConsentRecord;
use App\Services\Auth\SecurityAuditLogger;
use App\Services\Privacy\AccountDeletionService;
use App\Services\Privacy\ConsentRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class PrivacyController extends Controller
{
    public function consents(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'current_versions' => ['terms' => config('privacy.terms_version'), 'privacy' => config('privacy.privacy_version')],
            'consents' => $request->user()->consentRecords()->latest('recorded_at')->latest('id')->get()->map(fn (ConsentRecord $consent) => $this->consent($consent))->all(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function recordConsent(RecordConsentRequest $request, ConsentRecorder $recorder): JsonResponse
    {
        $purpose = $request->string('purpose')->toString();
        $consent = DB::transaction(function () use ($request, $recorder, $purpose): ConsentRecord {
            $consent = $recorder->record($request->user(), $purpose, $request->boolean('granted'), config('privacy.privacy_version'), 'settings', $request);
            if (! $consent->granted && in_array($purpose, ['marketing_email', 'marketing_push'], true)) {
                $column = $purpose === 'marketing_email' ? 'email_enabled' : 'push_enabled';
                $request->user()->notificationPreferences()->where('topic', 'marketing')->update([$column => false]);
            }

            return $consent;
        });

        return ApiResponse::success($this->consent($consent), 201);
    }

    public function export(Request $request): JsonResponse
    {
        $user = $request->user()->load(['profile', 'artistFollows.artist.profile', 'releaseFavorites.release', 'trackFavorites.track.release', 'listenerCollections.items.release', 'listenerCollections.items.track', 'notificationPreferences', 'pushDevices', 'listenerNotifications', 'consentRecords', 'accountDeletionRequest']);
        $payload = [
            'exported_at' => now()->utc()->toISOString(),
            'account' => ['id' => $user->public_id, 'email' => $user->email, 'display_name' => $user->profile?->display_name, 'created_at' => $user->created_at->utc()->toISOString()],
            'followed_artists' => $user->artistFollows->map(fn ($follow) => ['id' => $follow->artist->public_id, 'followed_at' => $follow->created_at->utc()->toISOString()])->all(),
            'favorite_releases' => $user->releaseFavorites->map(fn ($favorite) => ['id' => $favorite->release->public_id])->all(),
            'favorite_tracks' => $user->trackFavorites->map(fn ($favorite) => ['id' => $favorite->track->public_id])->all(),
            'collections' => $user->listenerCollections->map(fn ($collection) => ['id' => $collection->public_id, 'name' => $collection->name, 'description' => $collection->description, 'items' => $collection->items->map(fn ($item) => ['type' => $item->item_type, 'id' => $item->release?->public_id ?? $item->track?->public_id, 'position' => $item->position])->all()])->all(),
            'notification_preferences' => $user->notificationPreferences->makeHidden(['id', 'user_id', 'created_at', 'updated_at'])->toArray(),
            'push_devices' => $user->pushDevices->map(fn ($device) => ['id' => $device->public_id, 'platform' => $device->platform, 'enabled' => $device->disabled_at === null, 'last_seen_at' => $device->last_seen_at->utc()->toISOString()])->all(),
            'notifications' => $user->listenerNotifications->map(fn ($notification) => ['id' => $notification->public_id, 'type' => $notification->type, 'title' => $notification->title, 'body' => $notification->body, 'data' => $notification->data, 'read_at' => $notification->read_at?->utc()->toISOString(), 'created_at' => $notification->created_at->utc()->toISOString()])->all(),
            'consents' => $user->consentRecords->map(fn ($consent) => $this->consent($consent))->all(),
            'deletion' => $user->accountDeletionRequest ? ['status' => $user->accountDeletionRequest->status, 'scheduled_for' => $user->accountDeletionRequest->scheduled_for->utc()->toISOString()] : null,
        ];

        return response()->json(['data' => $payload])->header('Cache-Control', 'private, no-store')->header('Content-Disposition', 'attachment; filename="uncovr-data-export.json"');
    }

    public function deletionStatus(Request $request): JsonResponse
    {
        $deletion = $request->user()->accountDeletionRequest;

        return ApiResponse::success(['deletion' => $deletion ? ['status' => $deletion->status, 'requested_at' => $deletion->requested_at->utc()->toISOString(), 'scheduled_for' => $deletion->scheduled_for->utc()->toISOString()] : null]);
    }

    public function requestDeletion(RequestAccountDeletionRequest $request, AccountDeletionService $deletions, SecurityAuditLogger $audit): JsonResponse
    {
        if (! Hash::check($request->string('password')->toString(), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => ['The password is incorrect.']]);
        }
        $deletion = $deletions->schedule($request->user());
        $audit->record('privacy.account_deletion_requested', $request->user(), $request);

        return ApiResponse::success(['status' => $deletion->status, 'scheduled_for' => $deletion->scheduled_for->utc()->toISOString()], 202)->header('Cache-Control', 'no-store');
    }

    public function cancelDeletion(Request $request, AccountDeletionService $deletions, SecurityAuditLogger $audit): JsonResponse
    {
        $deletion = $deletions->cancel($request->user());
        $audit->record('privacy.account_deletion_cancelled', $request->user(), $request);

        return ApiResponse::success(['status' => $deletion->status]);
    }

    private function consent(ConsentRecord $consent): array
    {
        return ['id' => $consent->public_id, 'purpose' => $consent->purpose, 'granted' => $consent->granted, 'policy_version' => $consent->policy_version, 'source' => $consent->source, 'recorded_at' => $consent->recorded_at->utc()->toISOString()];
    }
}
