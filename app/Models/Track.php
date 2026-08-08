<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['release_id', 'position', 'title', 'duration_ms', 'isrc', 'is_explicit', 'created_by_user_id', 'updated_by_user_id'])]
class Track extends Model
{
    use HasPublicId, SoftDeletes;

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
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

    protected function casts(): array
    {
        return ['position' => 'integer', 'duration_ms' => 'integer', 'is_explicit' => 'boolean'];
    }
}
