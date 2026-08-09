<?php

namespace App\Services\Auth;

use App\Models\SecurityAuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SecurityAuditLogger
{
    private const FORBIDDEN_METADATA_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'refresh_token',
        'access_token',
        'session_id',
    ];

    /** @param array<string, scalar|null> $metadata */
    public function record(
        string $eventType,
        ?User $user = null,
        ?Request $request = null,
        ?string $deviceSessionPublicId = null,
        array $metadata = [],
    ): SecurityAuditEvent {
        $forbidden = array_intersect(array_keys($metadata), self::FORBIDDEN_METADATA_KEYS);

        if ($forbidden !== []) {
            throw new InvalidArgumentException('Security audit metadata contains a secret field.');
        }

        $userAgent = $request?->userAgent();

        return SecurityAuditEvent::query()->create([
            'user_id' => $user?->getKey(),
            'event_type' => $eventType,
            'device_session_public_id' => $deviceSessionPublicId,
            'ip_address' => $request?->ip(),
            'user_agent' => is_string($userAgent) ? Str::limit($userAgent, 1000, '') : null,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now()->startOfSecond(),
        ]);
    }

    /** @param array<string, scalar|null> $metadata */
    public function recordAfterResponse(
        string $eventType,
        ?User $user = null,
        ?Request $request = null,
        ?string $deviceSessionPublicId = null,
        array $metadata = [],
    ): void {
        $userId = $user?->getKey();
        $ip = $request?->ip();
        $userAgent = $request?->userAgent();

        app()->terminating(function () use ($eventType, $userId, $ip, $userAgent, $deviceSessionPublicId, $metadata): void {
            $this->recordFromSnapshot(
                $eventType,
                $userId,
                $ip,
                is_string($userAgent) ? $userAgent : null,
                $deviceSessionPublicId,
                $metadata,
            );
        });
    }

    /** @param array<string, scalar|null> $metadata */
    private function recordFromSnapshot(
        string $eventType,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        ?string $deviceSessionPublicId,
        array $metadata,
    ): SecurityAuditEvent {
        $forbidden = array_intersect(array_keys($metadata), self::FORBIDDEN_METADATA_KEYS);

        if ($forbidden !== []) {
            throw new InvalidArgumentException('Security audit metadata contains a secret field.');
        }

        return SecurityAuditEvent::query()->create([
            'user_id' => $userId,
            'event_type' => $eventType,
            'device_session_public_id' => $deviceSessionPublicId,
            'ip_address' => $ip,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 1000, ''),
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now()->startOfSecond(),
        ]);
    }
}
