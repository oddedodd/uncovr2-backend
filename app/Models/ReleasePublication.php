<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['release_id', 'version', 'content_fingerprint', 'title', 'subtitle', 'primary_artist_name', 'release_type', 'release_date', 'cover_url', 'search_text', 'snapshot', 'published_by_user_id', 'published_at', 'withdrawn_at'])]
class ReleasePublication extends Model
{
    use HasPublicId;

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(ReleasePublicationTrack::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'release_date' => 'immutable_date', 'snapshot' => 'array', 'published_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }
}
