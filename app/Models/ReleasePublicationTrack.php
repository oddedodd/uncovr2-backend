<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['release_publication_id', 'track_public_id', 'position', 'title', 'duration_ms', 'snapshot'])]
class ReleasePublicationTrack extends Model
{
    public function publication(): BelongsTo
    {
        return $this->belongsTo(ReleasePublication::class, 'release_publication_id');
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'duration_ms' => 'integer', 'snapshot' => 'array'];
    }
}
