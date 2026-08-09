<?php

namespace App\Models;

use App\Enums\ReleaseType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'artist_id', 'type', 'status', 'title', 'subtitle', 'description', 'release_date', 'upc', 'cover_media_id', 'created_by_user_id', 'updated_by_user_id', 'submitted_at', 'approved_at', 'approved_by_user_id', 'approved_fingerprint', 'scheduled_for', 'published_at', 'published_by_user_id', 'unpublished_at', 'archived_at', 'publication_version', 'featured_at'])]
class Release extends Model
{
    use HasPublicId, SoftDeletes;

    protected $attributes = ['status' => 'draft'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function ownerArtist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'release_artists')->withPivot(['is_primary', 'position'])->withTimestamps();
    }

    public function artistLinks(): HasMany
    {
        return $this->hasMany(ReleaseArtist::class);
    }

    public function editors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'release_editors')->withTimestamps();
    }

    public function editorAssignments(): HasMany
    {
        return $this->hasMany(ReleaseEditor::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->orderBy('position');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('position');
    }

    public function streamingLinks(): HasMany
    {
        return $this->hasMany(StreamingLink::class)->orderBy('position');
    }

    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class)->orderBy('position');
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(ReleaseActivityEvent::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ReleaseApprovalRequest::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ReleasePublication::class);
    }

    public function activePublication(): HasOne
    {
        return $this->hasOne(ReleasePublication::class)->whereNull('withdrawn_at');
    }

    public function ownerType(): string
    {
        return $this->organization_id ? 'organization' : 'artist';
    }

    protected function casts(): array
    {
        return [
            'type' => ReleaseType::class, 'release_date' => 'immutable_date',
            'submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'scheduled_for' => 'immutable_datetime',
            'published_at' => 'immutable_datetime', 'unpublished_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime',
            'featured_at' => 'immutable_datetime', 'publication_version' => 'integer',
        ];
    }
}
