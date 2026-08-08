<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['release_id', 'track_id', 'service', 'url', 'position', 'created_by_user_id', 'updated_by_user_id'])]
class StreamingLink extends Model
{
    use HasPublicId, SoftDeletes;

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function owningRelease(): Release
    {
        return $this->release ?? $this->track->release;
    }

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
