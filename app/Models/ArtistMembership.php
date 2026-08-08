<?php

namespace App\Models;

use App\Enums\ArtistRole;
use App\Enums\MembershipStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['artist_id', 'user_id', 'role', 'status', 'invited_by_user_id', 'joined_at', 'suspended_at'])]
class ArtistMembership extends Model
{
    use HasPublicId;

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'role' => ArtistRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
    }
}
