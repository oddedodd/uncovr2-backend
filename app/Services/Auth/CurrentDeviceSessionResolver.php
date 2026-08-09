<?php

namespace App\Services\Auth;

use App\Models\DeviceSession;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class CurrentDeviceSessionResolver
{
    private const REQUEST_ATTRIBUTE = 'current_device_session';

    public function resolve(Request $request): ?DeviceSession
    {
        if ($request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            $session = $request->attributes->get(self::REQUEST_ATTRIBUTE);

            return $session instanceof DeviceSession ? $session : null;
        }

        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken && $accessToken->device_session_id !== null) {
            return $this->remember($request, DeviceSession::query()->find($accessToken->device_session_id));
        }

        if (! $request->hasSession()) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, null);

            return null;
        }

        $session = DeviceSession::query()
            ->where('web_session_id', $request->session()->getId())
            ->first();

        if ($session !== null || config('session.driver') !== 'array') {
            return $this->remember($request, $session);
        }

        return $this->remember($request, $request->user()?->deviceSessions()
            ->where('client_type', 'portal')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first());
    }

    private function remember(Request $request, ?DeviceSession $session): ?DeviceSession
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $session);

        return $session;
    }
}
