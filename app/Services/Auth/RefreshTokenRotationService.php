<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Models\DeviceSession;
use App\Models\RefreshToken;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RefreshTokenRotationService
{
    public function __construct(
        private readonly RefreshTokenGenerator $tokenGenerator,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly CurrentDeviceSessionResolver $sessionResolver,
    ) {}

    /** @return array<string, mixed>|null */
    public function rotate(string $plainRefreshToken, Request $request): ?array
    {
        $tokenHash = hash('sha256', $plainRefreshToken);
        $tokenReference = RefreshToken::query()
            ->select(['id', 'device_session_id'])
            ->where('token_hash', $tokenHash)
            ->first();

        if ($tokenReference === null) {
            return null;
        }

        $plainSuccessor = $this->tokenGenerator->generate();
        $outcome = DB::transaction(function () use (
            $tokenReference,
            $tokenHash,
            $plainSuccessor,
            $request,
        ): array {
            $now = now()->startOfSecond();
            $session = DeviceSession::query()
                ->with('user')
                ->whereKey($tokenReference->device_session_id)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return ['status' => 'invalid'];
            }

            $refreshToken = RefreshToken::query()
                ->whereKey($tokenReference->getKey())
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($refreshToken === null || $session->client_type !== 'mobile') {
                return ['status' => 'invalid'];
            }

            if ($session->revoked_at !== null) {
                return ['status' => 'invalid'];
            }

            if ($session->user->status === UserStatus::Suspended) {
                $this->revokeSession($session, $now, 'account_suspended');

                return ['status' => 'invalid'];
            }

            if ($refreshToken->used_at !== null) {
                $this->revokeSession($session, $now, 'refresh_token_reuse');
                $this->auditLogger->record(
                    'auth.refresh_token_reused',
                    $session->user,
                    $request,
                    $session->public_id,
                );

                return [
                    'status' => 'reused',
                    'session_id' => $session->public_id,
                    'user_id' => $session->user->public_id,
                ];
            }

            if (
                $refreshToken->revoked_at !== null
                || $refreshToken->expires_at->lessThanOrEqualTo($now)
                || $session->idle_expires_at->lessThanOrEqualTo($now)
                || $session->absolute_expires_at->lessThanOrEqualTo($now)
            ) {
                $this->revokeSession($session, $now, 'session_expired');

                return ['status' => 'invalid'];
            }

            $refreshExpiresAt = $now->copy()
                ->addDays(config('authentication.refresh_token_idle_ttl_days'))
                ->min($session->absolute_expires_at);
            $accessExpiresAt = $now->copy()
                ->addMinutes(config('authentication.access_token_ttl_minutes'))
                ->min($session->absolute_expires_at);

            $successor = RefreshToken::query()->create([
                'device_session_id' => $session->getKey(),
                'token_hash' => hash('sha256', $plainSuccessor),
                'generation' => $refreshToken->generation + 1,
                'expires_at' => $refreshExpiresAt,
            ]);

            $refreshToken->forceFill([
                'used_at' => $now,
                'replaced_by_id' => $successor->getKey(),
            ])->save();

            $session->user->tokens()
                ->where('device_session_id', $session->getKey())
                ->delete();

            $newAccessToken = $session->user->createToken(
                name: 'mobile:'.$session->public_id,
                abilities: ['mobile:access'],
                expiresAt: $accessExpiresAt,
            );
            $newAccessToken->accessToken->device_session_id = $session->getKey();
            $newAccessToken->accessToken->save();

            $userAgent = $request->userAgent();
            $session->forceFill([
                'last_ip_address' => $request->ip(),
                'user_agent' => is_string($userAgent) ? Str::limit($userAgent, 1000, '') : null,
                'last_used_at' => $now,
                'idle_expires_at' => $refreshExpiresAt,
            ])->save();
            $this->sessionResolver->cacheSession($session);

            $this->auditLogger->record(
                'auth.token_refreshed',
                $session->user,
                $request,
                $session->public_id,
            );

            return [
                'status' => 'rotated',
                'session' => $this->sessionResource($session),
                'authentication' => [
                    'type' => 'bearer',
                    'token_type' => 'Bearer',
                    'access_token' => $newAccessToken->plainTextToken,
                    'access_token_expires_at' => $this->timestamp($accessExpiresAt),
                    'refresh_token' => $plainSuccessor,
                    'refresh_token_expires_at' => $this->timestamp($refreshExpiresAt),
                ],
            ];
        }, attempts: 3);

        if ($outcome['status'] === 'reused') {
            Log::warning('auth.refresh_token_reuse_detected', [
                'user_id' => $outcome['user_id'],
                'device_session_id' => $outcome['session_id'],
            ]);
        }

        if ($outcome['status'] !== 'rotated') {
            return null;
        }

        unset($outcome['status']);

        return $outcome;
    }

    private function revokeSession(
        DeviceSession $session,
        CarbonInterface $now,
        string $reason,
    ): void {
        $session->forceFill([
            'revoked_at' => $now,
            'revocation_reason' => $reason,
        ])->save();

        RefreshToken::query()
            ->where('device_session_id', $session->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);

        $session->user->tokens()
            ->where('device_session_id', $session->getKey())
            ->delete();
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

    private function timestamp(CarbonInterface $timestamp): string
    {
        return $timestamp->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
