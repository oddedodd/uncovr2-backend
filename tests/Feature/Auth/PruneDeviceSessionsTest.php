<?php

namespace Tests\Feature\Auth;

use App\Models\DeviceSession;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PruneDeviceSessionsTest extends TestCase
{
    public function test_it_prunes_only_sessions_past_the_security_retention_period(): void
    {
        Carbon::setTestNow('2026-08-08 10:15:30');
        $user = User::factory()->create();
        $prunable = $this->deviceSession($user, now()->subDays(31));
        $retained = $this->deviceSession($user, now()->subDays(29));

        RefreshToken::query()->create([
            'device_session_id' => $prunable->getKey(),
            'token_hash' => hash('sha256', 'prunable-token'),
            'generation' => 0,
            'expires_at' => now()->subDays(31),
            'used_at' => now()->subDays(31),
            'revoked_at' => now()->subDays(31),
        ]);
        RefreshToken::query()->create([
            'device_session_id' => $retained->getKey(),
            'token_hash' => hash('sha256', 'retained-token'),
            'generation' => 0,
            'expires_at' => now()->subDays(29),
            'used_at' => now()->subDays(29),
            'revoked_at' => now()->subDays(29),
        ]);

        $this->artisan('auth:prune-device-sessions')
            ->expectsOutput('Pruned 1 expired device session(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('device_sessions', ['id' => $prunable->getKey()]);
        $this->assertDatabaseHas('device_sessions', ['id' => $retained->getKey()]);
        $this->assertDatabaseCount('refresh_tokens', 1);
        $this->assertDatabaseHas('refresh_tokens', [
            'device_session_id' => $retained->getKey(),
        ]);
    }

    private function deviceSession(User $user, Carbon $absoluteExpiresAt): DeviceSession
    {
        return DeviceSession::query()->create([
            'user_id' => $user->getKey(),
            'client_type' => 'mobile',
            'device_name' => 'Test iPhone',
            'last_used_at' => $absoluteExpiresAt,
            'idle_expires_at' => $absoluteExpiresAt,
            'absolute_expires_at' => $absoluteExpiresAt,
            'revoked_at' => $absoluteExpiresAt,
            'revocation_reason' => 'session_expired',
        ]);
    }
}
