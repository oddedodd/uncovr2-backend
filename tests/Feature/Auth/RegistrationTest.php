<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    private const PASSWORD = 'a secure passphrase';

    public function test_a_user_can_register_without_receiving_a_session_or_token(): void
    {
        Notification::fake();

        $response = $this->postApi('/auth/register', [
            'display_name' => '  Ada   Artist  ',
            'email' => '  ADA@Example.com ',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $this->assertApiSuccess($response, [
            'message' => 'If the address can be registered, a verification email will be sent.',
        ], 202)->assertHeader('Cache-Control', 'no-store, private');

        $user = User::query()->with('profile')->sole();

        $this->assertSame('ada@example.com', $user->email);
        $this->assertSame('Ada Artist', $user->profile?->display_name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertNotEmpty($user->public_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('device_sessions', 0);

        Notification::assertSentTo(
            $user,
            VerifyEmailNotification::class,
            fn (VerifyEmailNotification $notification): bool => $notification->version === 1,
        );
    }

    public function test_duplicate_registration_is_case_insensitive_and_enumeration_safe(): void
    {
        Notification::fake();
        $existing = User::factory()->unverified()->create(['email' => 'artist@example.com']);

        $payload = [
            'display_name' => 'Different Name',
            'email' => ' ARTIST@EXAMPLE.COM ',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];

        $response = $this->postApi('/auth/register', $payload);

        $this->assertApiSuccess($response, [
            'message' => 'If the address can be registered, a verification email will be sent.',
        ], 202);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(0, $existing->fresh()->email_verification_version);
        Notification::assertNothingSent();
    }

    public function test_registration_validates_minimal_account_data_and_a_long_passphrase(): void
    {
        Notification::fake();

        $response = $this->postApi('/auth/register', [
            'display_name' => 'A',
            'email' => 'not-an-email',
            'password' => 'too short',
            'password_confirmation' => 'different',
        ]);

        $this->assertApiError($response, 422, 'validation_failed')
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'fields' => ['display_name', 'email', 'password'],
                    ],
                ],
            ]);
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
    }
}
