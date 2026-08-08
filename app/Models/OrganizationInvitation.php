<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

#[Fillable(['organization_id', 'email', 'role', 'token_hash', 'invited_by_user_id', 'accepted_by_user_id', 'expires_at', 'accepted_at', 'revoked_at', 'last_sent_at', 'send_count'])]
class OrganizationInvitation extends Model
{
    use HasPublicId, Notifiable;

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => strtolower(trim($value)));
    }

    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
            'send_count' => 'integer',
        ];
    }
}
