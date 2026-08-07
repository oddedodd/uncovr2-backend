<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationDesignTest extends TestCase
{
    public function test_token_and_session_lifetimes_are_explicit_and_consistent(): void
    {
        $this->assertSame(15, config('authentication.access_token_ttl_minutes'));
        $this->assertSame(15, config('sanctum.expiration'));
        $this->assertSame(30, config('authentication.refresh_token_idle_ttl_days'));
        $this->assertSame(90, config('authentication.mobile_session_absolute_ttl_days'));
        $this->assertSame(120, config('authentication.portal_session_idle_ttl_minutes'));
        $this->assertSame(
            config('session.lifetime'),
            config('authentication.portal_session_idle_ttl_minutes'),
        );
        $this->assertSame(720, config('authentication.portal_session_absolute_ttl_minutes'));
        $this->assertSame(32, config('authentication.refresh_token_bytes'));
        $this->assertSame('uncovr_refresh_', config('authentication.refresh_token_prefix'));
        $this->assertSame(30, config('authentication.refresh_token_retention_days'));

        $this->assertLessThan(
            config('authentication.mobile_session_absolute_ttl_days'),
            config('authentication.refresh_token_idle_ttl_days'),
        );
    }

    public function test_device_session_and_refresh_token_schema_is_available(): void
    {
        $this->assertTrue(Schema::hasColumns('device_sessions', [
            'id',
            'public_id',
            'user_id',
            'client_type',
            'device_name',
            'web_session_id',
            'last_used_at',
            'idle_expires_at',
            'absolute_expires_at',
            'revoked_at',
        ]));

        $this->assertTrue(Schema::hasColumns('refresh_tokens', [
            'id',
            'device_session_id',
            'token_hash',
            'generation',
            'expires_at',
            'used_at',
            'revoked_at',
            'replaced_by_id',
        ]));

        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'device_session_id'));
    }

    public function test_expired_sanctum_tokens_are_scheduled_for_pruning(): void
    {
        Artisan::call('schedule:list');
        $schedule = Artisan::output();

        $this->assertStringContainsString(
            'sanctum:prune-expired --hours=24',
            $schedule,
        );
        $this->assertStringContainsString(
            'auth:prune-device-sessions',
            $schedule,
        );
    }

    public function test_a_device_session_can_have_only_one_sanctum_access_token(): void
    {
        $user = User::factory()->create();
        $deviceSessionId = $this->createDeviceSession($user);

        $this->createAccessToken($user, $deviceSessionId, 'first-token');

        $this->expectException(QueryException::class);

        $this->createAccessToken($user, $deviceSessionId, 'second-token');
    }

    public function test_refresh_generations_are_unique_within_a_device_session(): void
    {
        $user = User::factory()->create();
        $deviceSessionId = $this->createDeviceSession($user);

        $this->createRefreshToken($deviceSessionId, generation: 0, token: 'first-refresh');

        $this->expectException(QueryException::class);

        $this->createRefreshToken($deviceSessionId, generation: 0, token: 'duplicate-generation');
    }

    private function createDeviceSession(User $user): int
    {
        return DB::table('device_sessions')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'user_id' => $user->getKey(),
            'client_type' => 'mobile',
            'device_name' => 'Test phone',
            'last_used_at' => now(),
            'idle_expires_at' => now()->addDays(30),
            'absolute_expires_at' => now()->addDays(90),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAccessToken(User $user, int $deviceSessionId, string $token): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->getKey(),
            'device_session_id' => $deviceSessionId,
            'name' => 'Test token',
            'token' => hash('sha256', $token),
            'abilities' => '["mobile:access"]',
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRefreshToken(int $deviceSessionId, int $generation, string $token): void
    {
        DB::table('refresh_tokens')->insert([
            'device_session_id' => $deviceSessionId,
            'token_hash' => hash('sha256', $token),
            'generation' => $generation,
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
