<?php

namespace App\Services\Auth;

use App\Models\DeviceSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        $webSessionId = $request->session()->getId();
        $session = $this->portalSession($webSessionId);

        if ($session !== null || config('session.driver') !== 'array') {
            return $this->remember($request, $session);
        }

        return $this->remember($request, $request->user()?->deviceSessions()
            ->where('client_type', 'portal')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first());
    }

    public function cachePortalSession(DeviceSession $session): void
    {
        if ($session->web_session_id === null || $this->portalCacheSeconds() === 0) {
            return;
        }

        Cache::put(
            $this->portalCacheKey($session->web_session_id),
            $this->portalCachePayload($session),
            now()->addSeconds($this->portalCacheSeconds()),
        );
    }

    public function forgetPortalSession(?string $webSessionId): void
    {
        if ($webSessionId === null || $this->portalCacheSeconds() === 0) {
            return;
        }

        Cache::forget($this->portalCacheKey($webSessionId));
    }

    private function portalSession(string $webSessionId): ?DeviceSession
    {
        if ($this->portalCacheSeconds() === 0) {
            return DeviceSession::query()
                ->where('web_session_id', $webSessionId)
                ->first();
        }

        $cacheKey = $this->portalCacheKey($webSessionId);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            $session = $this->sessionFromPortalCache($cached, $webSessionId);
            if ($session !== null) {
                return $session;
            }

            Cache::forget($cacheKey);
        }

        $session = DeviceSession::query()
            ->where('web_session_id', $webSessionId)
            ->first();

        if ($session !== null) {
            $this->cachePortalSession($session);
        }

        return $session;
    }

    private function remember(Request $request, ?DeviceSession $session): ?DeviceSession
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $session);

        return $session;
    }

    private function portalCacheKey(string $webSessionId): string
    {
        return 'auth:portal-device-session:'.hash('sha256', $webSessionId);
    }

    private function portalCacheSeconds(): int
    {
        return (int) config('authentication.portal_device_session_cache_seconds');
    }

    /**
     * @return array<string, scalar|null>
     */
    private function portalCachePayload(DeviceSession $session): array
    {
        return [
            'id' => $session->getKey(),
            'public_id' => $session->public_id,
            'user_id' => $session->user_id,
            'client_type' => $session->client_type,
            'device_name' => $session->device_name,
            'platform' => $session->platform,
            'app_version' => $session->app_version,
            'web_session_id' => $session->web_session_id,
            'last_ip_address' => $session->last_ip_address,
            'user_agent' => $session->user_agent,
            'last_used_at' => $session->last_used_at?->toDateTimeString(),
            'idle_expires_at' => $session->idle_expires_at?->toDateTimeString(),
            'absolute_expires_at' => $session->absolute_expires_at?->toDateTimeString(),
            'revoked_at' => $session->revoked_at?->toDateTimeString(),
            'revocation_reason' => $session->revocation_reason,
            'created_at' => $session->created_at?->toDateTimeString(),
            'updated_at' => $session->updated_at?->toDateTimeString(),
        ];
    }

    private function sessionFromPortalCache(mixed $cached, string $webSessionId): ?DeviceSession
    {
        if (! is_array($cached) || ($cached['web_session_id'] ?? null) !== $webSessionId) {
            return null;
        }

        foreach (['id', 'public_id', 'user_id', 'client_type', 'last_used_at', 'idle_expires_at', 'absolute_expires_at'] as $key) {
            if (! array_key_exists($key, $cached)) {
                return null;
            }
        }

        return (new DeviceSession)->newFromBuilder($cached);
    }
}
