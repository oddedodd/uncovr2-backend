<?php

namespace App\Services\Auth;

use App\Models\DeviceSession;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class CurrentDeviceSessionResolver
{
    public function resolve(Request $request): ?DeviceSession
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken && $accessToken->device_session_id !== null) {
            return DeviceSession::query()->find($accessToken->device_session_id);
        }

        if (! $request->hasSession()) {
            return null;
        }

        $session = DeviceSession::query()
            ->where('web_session_id', $request->session()->getId())
            ->first();

        if ($session !== null || config('session.driver') !== 'array') {
            return $session;
        }

        return $request->user()?->deviceSessions()
            ->where('client_type', 'portal')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }
}
