<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['created_by_user_id', 'status', 'suspended_at'])]
class Organization extends Model
{
    use HasPublicId;

    protected $attributes = ['status' => 'active'];

    public function profile(): HasOne
    {
        return $this->hasOne(OrganizationProfile::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function artistRelationships(): HasMany
    {
        return $this->hasMany(OrganizationArtistRelationship::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return ['suspended_at' => 'immutable_datetime'];
    }
}
