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
            return $this->remember($request, $this->bearerSession($accessToken->device_session_id));
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

    /**
     * Refreshes every cache entry that can resolve to this session. Call after any
     * write to the session, otherwise a stale entry keeps being served.
     */
    public function cacheSession(DeviceSession $session): void
    {
        if ($this->bearerCacheSeconds() > 0) {
            Cache::put(
                $this->bearerCacheKey($session->getKey()),
                $this->cachePayload($session),
                now()->addSeconds($this->bearerCacheSeconds()),
            );
        }

        if ($session->web_session_id !== null && $this->portalCacheSeconds() > 0) {
            Cache::put(
                $this->portalCacheKey($session->web_session_id),
                $this->cachePayload($session),
                now()->addSeconds($this->portalCacheSeconds()),
            );
        }
    }

    public function forgetSession(DeviceSession $session): void
    {
        Cache::forget($this->bearerCacheKey($session->getKey()));

        if ($session->web_session_id !== null) {
            Cache::forget($this->portalCacheKey($session->web_session_id));
        }
    }

    private function bearerSession(int|string $deviceSessionId): ?DeviceSession
    {
        if ($this->bearerCacheSeconds() === 0) {
            return DeviceSession::query()->find($deviceSessionId);
        }

        $cacheKey = $this->bearerCacheKey($deviceSessionId);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            $session = $this->sessionFromCache($cached, 'id', $deviceSessionId);
            if ($session !== null) {
                return $session;
            }

            Cache::forget($cacheKey);
        }

        $session = DeviceSession::query()->find($deviceSessionId);

        if ($session !== null) {
            $this->cacheSession($session);
        }

        return $session;
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
            $session = $this->sessionFromCache($cached, 'web_session_id', $webSessionId);
            if ($session !== null) {
                return $session;
            }

            Cache::forget($cacheKey);
        }

        $session = DeviceSession::query()
            ->where('web_session_id', $webSessionId)
            ->first();

        if ($session !== null) {
            $this->cacheSession($session);
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

    private function bearerCacheKey(int|string $deviceSessionId): string
    {
        return 'auth:bearer-device-session:'.$deviceSessionId;
    }

    private function portalCacheSeconds(): int
    {
        return (int) config('authentication.portal_device_session_cache_seconds');
    }

    private function bearerCacheSeconds(): int
    {
        return (int) config('authentication.bearer_device_session_cache_seconds');
    }

    /**
     * @return array<string, scalar|null>
     */
    private function cachePayload(DeviceSession $session): array
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

    private function sessionFromCache(mixed $cached, string $identityKey, int|string $expected): ?DeviceSession
    {
        $identity = is_array($cached) ? ($cached[$identityKey] ?? null) : null;

        if ($identity === null || ! is_scalar($identity) || (string) $identity !== (string) $expected) {
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
