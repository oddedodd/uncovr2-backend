<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['release_id', 'version', 'content_fingerprint', 'snapshot', 'published_by_user_id', 'published_at', 'withdrawn_at'])]
class ReleasePublication extends Model
{
    use HasPublicId;

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array', 'published_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }
}
