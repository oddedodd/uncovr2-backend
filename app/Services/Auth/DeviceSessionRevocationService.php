<?php

namespace App\Services\Auth;

use App\Models\DeviceSession;
use App\Models\PushDevice;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeviceSessionRevocationService
{
    public function revoke(DeviceSession $deviceSession, string $reason): bool
    {
        return DB::transaction(function () use ($deviceSession, $reason): bool {
            $session = DeviceSession::query()
                ->whereKey($deviceSession->getKey())
                ->lockForUpdate()
                ->first();

            if ($session === null || $session->revoked_at !== null) {
                return false;
            }

            $this->revokeLockedSession($session, $reason);

            return true;
        }, attempts: 3);
    }

    public function revokeAll(User $user, string $reason): int
    {
        return DB::transaction(function () use ($user, $reason): int {
            $sessions = DeviceSession::query()
                ->where('user_id', $user->getKey())
                ->whereNull('revoked_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $this->revokeLockedSession($session, $reason);
            }

            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))
                    ->where('user_id', $user->getKey())
                    ->delete();
            }

            return $sessions->count();
        }, attempts: 3);
    }

    private function revokeLockedSession(DeviceSession $session, string $reason): void
    {
        $now = now()->startOfSecond();
        $session->forceFill([
            'revoked_at' => $now,
            'revocation_reason' => $reason,
        ])->save();

        RefreshToken::query()
            ->where('device_session_id', $session->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);

        DB::table('personal_access_tokens')
            ->where('device_session_id', $session->getKey())
            ->delete();

        PushDevice::query()->where('device_session_id', $session->getKey())->whereNull('disabled_at')->update(['disabled_at' => $now]);

        if ($session->web_session_id !== null && config('session.driver') === 'database') {
            DB::table(config('session.table'))
                ->where('id', $session->web_session_id)
                ->delete();
        }
    }
}
