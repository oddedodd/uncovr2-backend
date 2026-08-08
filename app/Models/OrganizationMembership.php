<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'user_id', 'role', 'status', 'invited_by_user_id', 'joined_at', 'suspended_at'])]
class OrganizationMembership extends Model
{
    use HasPublicId;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
            'role' => OrganizationRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
    }
}
