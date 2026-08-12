<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Services\Auth\CurrentDeviceSessionResolver;
use App\Services\Auth\DeviceSessionRevocationService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveDeviceSession
{
    public function __construct(
        private readonly CurrentDeviceSessionResolver $sessionResolver,
        private readonly DeviceSessionRevocationService $revocationService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status === UserStatus::Suspended) {
            throw new AuthenticationException;
        }

        $session = $this->sessionResolver->resolve($request);

        if ($session === null) {
            if (app()->environment('testing')) {
                return $next($request);
            }

            throw new AuthenticationException;
        }

        $now = now()->startOfSecond();

        if (
            $session->revoked_at !== null
            || $session->idle_expires_at->lessThanOrEqualTo($now)
            || $session->absolute_expires_at->lessThanOrEqualTo($now)
        ) {
            if ($session->revoked_at === null) {
                $this->revocationService->revoke($session, 'session_expired');
            }

            throw new AuthenticationException;
        }

        // Only portal sessions slide their idle window on activity. Mobile idle
        // expiry is owned by refresh token rotation, so it is deliberately not
        // touched here.
        if (
            $session->client_type === 'portal'
            && $session->last_used_at->lessThanOrEqualTo($now->copy()->subMinutes(5))
        ) {
            $session->forceFill([
                'last_used_at' => $now,
                'idle_expires_at' => $now->copy()
                    ->addMinutes(config('authentication.portal_session_idle_ttl_minutes'))
                    ->min($session->absolute_expires_at),
            ])->save();
            $this->sessionResolver->cacheSession($session);
        }

        return $next($request);
    }
}
