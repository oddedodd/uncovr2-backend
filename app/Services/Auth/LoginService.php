<?php

namespace App\Services\Auth;

use App\Models\DeviceSession;
use App\Models\RefreshToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class LoginService
{
    /** @return array<string, mixed> */
    public function portal(User $user, Request $request, array $device): array
    {
        $guard = Auth::guard('web');
        $guard->login($user);
        $request->session()->regenerate();

        try {
            $now = now()->startOfSecond();
            $deviceSession = DeviceSession::query()->create([
                ...$this->deviceAttributes($user, $request, $device),
                'client_type' => 'portal',
                'web_session_id' => $request->session()->getId(),
                'last_used_at' => $now,
                'idle_expires_at' => $now->copy()->addMinutes(
                    config('authentication.portal_session_idle_ttl_minutes'),
                ),
                'absolute_expires_at' => $now->copy()->addMinutes(
                    config('authentication.portal_session_absolute_ttl_minutes'),
                ),
            ]);
        } catch (Throwable $exception) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw $exception;
        }

        return [
            'user' => $this->userResource($user),
            'session' => $this->sessionResource($deviceSession),
            'authentication' => [
                'type' => 'session',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function mobile(User $user, Request $request, array $device): array
    {
        $now = now()->startOfSecond();
        $absoluteExpiresAt = $now->copy()->addDays(
            config('authentication.mobile_session_absolute_ttl_days'),
        );
        $refreshExpiresAt = $now->copy()->addDays(
            config('authentication.refresh_token_idle_ttl_days'),
        )->min($absoluteExpiresAt);
        $accessExpiresAt = $now->copy()->addMinutes(
            config('authentication.access_token_ttl_minutes'),
        );
        $plainRefreshToken = $this->newRefreshToken();

        [$deviceSession, $plainAccessToken] = DB::transaction(
            function () use (
                $user,
                $request,
                $device,
                $now,
                $absoluteExpiresAt,
                $refreshExpiresAt,
                $accessExpiresAt,
                $plainRefreshToken,
            ): array {
                $deviceSession = DeviceSession::query()->create([
                    ...$this->deviceAttributes($user, $request, $device),
                    'client_type' => 'mobile',
                    'last_used_at' => $now,
                    'idle_expires_at' => $refreshExpiresAt,
                    'absolute_expires_at' => $absoluteExpiresAt,
                ]);

                RefreshToken::query()->create([
                    'device_session_id' => $deviceSession->getKey(),
                    'token_hash' => hash('sha256', $plainRefreshToken),
                    'generation' => 0,
                    'expires_at' => $refreshExpiresAt,
                ]);

                $newAccessToken = $user->createToken(
                    name: 'mobile:'.$deviceSession->public_id,
                    abilities: ['mobile:access'],
                    expiresAt: $accessExpiresAt,
                );
                $newAccessToken->accessToken->device_session_id = $deviceSession->getKey();
                $newAccessToken->accessToken->save();

                return [$deviceSession, $newAccessToken->plainTextToken];
            },
        );

        event(new Login('sanctum', $user, false));

        return [
            'user' => $this->userResource($user),
            'session' => $this->sessionResource($deviceSession),
            'authentication' => [
                'type' => 'bearer',
                'token_type' => 'Bearer',
                'access_token' => $plainAccessToken,
                'access_token_expires_at' => $this->timestamp($accessExpiresAt),
                'refresh_token' => $plainRefreshToken,
                'refresh_token_expires_at' => $this->timestamp($refreshExpiresAt),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function deviceAttributes(User $user, Request $request, array $device): array
    {
        $userAgent = $request->userAgent();

        return [
            'user_id' => $user->getKey(),
            'device_name' => $device['name'],
            'platform' => $device['platform'] ?? null,
            'app_version' => $device['app_version'] ?? null,
            'last_ip_address' => $request->ip(),
            'user_agent' => is_string($userAgent) ? Str::limit($userAgent, 1000, '') : null,
        ];
    }

    /** @return array<string, mixed> */
    private function userResource(User $user): array
    {
        return [
            'id' => $user->public_id,
            'email' => $user->email,
            'profile' => [
                'display_name' => $user->profile?->display_name,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function sessionResource(DeviceSession $session): array
    {
        return [
            'id' => $session->public_id,
            'client_type' => $session->client_type,
            'device_name' => $session->device_name,
            'platform' => $session->platform,
            'app_version' => $session->app_version,
            'last_used_at' => $this->timestamp($session->last_used_at),
            'idle_expires_at' => $this->timestamp($session->idle_expires_at),
            'absolute_expires_at' => $this->timestamp($session->absolute_expires_at),
        ];
    }

    private function newRefreshToken(): string
    {
        $entropy = random_bytes(config('authentication.refresh_token_bytes'));

        return config('authentication.refresh_token_prefix')
            .rtrim(strtr(base64_encode($entropy), '+/', '-_'), '=');
    }

    private function timestamp(CarbonInterface $timestamp): string
    {
        return $timestamp->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
