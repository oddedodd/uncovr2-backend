<?php

namespace App\Services\Privacy;

use App\Models\AccountDeletionRequest;
use App\Models\User;
use App\Services\Auth\DeviceSessionRevocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountDeletionService
{
    public function __construct(private readonly DeviceSessionRevocationService $sessions) {}

    public function schedule(User $user): AccountDeletionRequest
    {
        return DB::transaction(function () use ($user): AccountDeletionRequest {
            $now = now();
            $deletion = AccountDeletionRequest::query()->where('user_id', $user->getKey())->lockForUpdate()->first() ?? new AccountDeletionRequest(['user_id' => $user->getKey()]);
            $deletion->fill(['status' => 'scheduled', 'requested_at' => $now, 'scheduled_for' => $now->copy()->addDays(config('privacy.deletion_grace_days')), 'cancelled_at' => null, 'completed_at' => null])->save();
            $user->forceFill(['deletion_requested_at' => $now])->save();
            $this->sessions->revokeAll($user, 'account_deletion_requested');

            return $deletion;
        }, attempts: 3);
    }

    public function cancel(User $user): AccountDeletionRequest
    {
        return DB::transaction(function () use ($user): AccountDeletionRequest {
            $deletion = AccountDeletionRequest::query()->where('user_id', $user->getKey())->where('status', 'scheduled')->lockForUpdate()->firstOrFail();
            $deletion->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $user->forceFill(['deletion_requested_at' => null])->save();

            return $deletion;
        }, attempts: 3);
    }

    public function processDue(): int
    {
        $processed = 0;
        AccountDeletionRequest::query()->where('status', 'scheduled')->where('scheduled_for', '<=', now())->orderBy('id')
            ->chunkById(100, function ($requests) use (&$processed): void {
                foreach ($requests as $deletion) {
                    $processed += DB::transaction(function () use ($deletion): int {
                        $request = AccountDeletionRequest::query()->whereKey($deletion->getKey())->where('status', 'scheduled')->lockForUpdate()->first();
                        if (! $request || $request->scheduled_for->isFuture()) {
                            return 0;
                        }
                        $user = User::query()->lockForUpdate()->findOrFail($request->user_id);
                        $this->sessions->revokeAll($user, 'account_deleted');
                        $user->artistFollows()->delete();
                        $user->releaseFavorites()->delete();
                        $user->trackFavorites()->delete();
                        $user->listenerCollections()->delete();
                        $user->notificationPreferences()->delete();
                        $user->pushDevices()->delete();
                        $user->listenerNotifications()->delete();
                        $user->organizationMemberships()->delete();
                        $user->artistMemberships()->delete();
                        $user->profile()->update(['display_name' => 'Deleted user']);
                        $user->forceFill([
                            'email' => 'deleted+'.$user->public_id.'@users.invalid', 'password' => Str::random(64),
                            'email_verified_at' => null, 'is_superadmin' => false, 'deletion_requested_at' => null, 'anonymized_at' => now(),
                        ])->save();
                        $request->update(['status' => 'completed', 'completed_at' => now()]);

                        return 1;
                    }, attempts: 3);
                }
            });

        return $processed;
    }
}
