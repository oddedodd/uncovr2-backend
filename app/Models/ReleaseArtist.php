<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['release_id', 'artist_id', 'is_primary', 'position'])]
class ReleaseArtist extends Model
{
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'position' => 'integer'];
    }
}
