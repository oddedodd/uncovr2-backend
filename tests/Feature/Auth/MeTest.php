<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeTest extends TestCase
{
    public function test_an_authenticated_user_can_read_and_update_their_minimal_profile(): void
    {
        $user = User::factory()->create(['email' => 'artist@example.com']);
        $user->profile()->create(['display_name' => 'Old Name']);
        Sanctum::actingAs($user, ['mobile:access']);

        $this->getApi('/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id)
            ->assertJsonPath('data.email', 'artist@example.com')
            ->assertJsonPath('data.profile.display_name', 'Old Name')
            ->assertJsonMissingPath('data.password');

        $this->patchApi('/me', ['display_name' => '  Ada   Artist  '])
            ->assertOk()
            ->assertJsonPath('data.profile.display_name', 'Ada Artist')
            ->assertJsonPath('data.email', 'artist@example.com');

        $this->assertSame('Ada Artist', $user->profile()->sole()->display_name);
        $this->assertDatabaseHas('security_audit_events', [
            'user_id' => $user->getKey(),
            'event_type' => 'account.profile_updated',
        ]);
    }

    public function test_email_cannot_be_changed_through_the_profile_endpoint(): void
    {
        $user = User::factory()->create(['email' => 'artist@example.com']);
        $user->profile()->create(['display_name' => 'Ada Artist']);
        Sanctum::actingAs($user, ['mobile:access']);

        $this->assertApiError($this->patchApi('/me', [
            'display_name' => 'Ada Artist',
            'email' => 'attacker@example.com',
        ]), 422, 'validation_failed');

        $this->assertSame('artist@example.com', $user->fresh()->email);
    }

    public function test_me_endpoints_require_authentication(): void
    {
        $this->getApi('/me')->assertUnauthorized();
        $this->patchApi('/me', ['display_name' => 'Ada'])->assertUnauthorized();
        $this->getApi('/me/sessions')->assertUnauthorized();
    }
}
