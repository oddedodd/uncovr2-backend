<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Models\Concerns\HasPublicId;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable;

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** @return HasMany<DeviceSession, $this> */
    public function deviceSessions(): HasMany
    {
        return $this->hasMany(DeviceSession::class);
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function artistMemberships(): HasMany
    {
        return $this->hasMany(ArtistMembership::class);
    }

    public function releaseEditorAssignments(): HasMany
    {
        return $this->hasMany(ReleaseEditor::class);
    }

    public function artistFollows(): HasMany
    {
        return $this->hasMany(ArtistFollow::class);
    }

    public function releaseFavorites(): HasMany
    {
        return $this->hasMany(ReleaseFavorite::class);
    }

    public function trackFavorites(): HasMany
    {
        return $this->hasMany(TrackFavorite::class);
    }

    public function listenerCollections(): HasMany
    {
        return $this->hasMany(ListenerCollection::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function pushDevices(): HasMany
    {
        return $this->hasMany(PushDevice::class);
    }

    public function listenerNotifications(): HasMany
    {
        return $this->hasMany(ListenerNotification::class);
    }

    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    public function accountDeletionRequest(): HasOne
    {
        return $this->hasOne(AccountDeletionRequest::class);
    }

    public function sendEmailVerificationNotification(): void
    {
        DB::transaction(function (): void {
            /** @var self|null $user */
            $user = self::query()->lockForUpdate()->find($this->getKey());

            if ($user === null || $user->hasVerifiedEmail()) {
                return;
            }

            $user->increment('email_verification_version');
            $user->refresh();

            $user->notify(new VerifyEmailNotification(
                version: $user->email_verification_version,
            ));
        });
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => strtolower(trim($value)),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_version' => 'integer',
            'is_superadmin' => 'boolean',
            'status' => UserStatus::class,
            'suspended_at' => 'immutable_datetime',
            'deletion_requested_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }
}
