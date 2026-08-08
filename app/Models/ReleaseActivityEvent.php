<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['release_id', 'user_id', 'event_type', 'subject_type', 'subject_public_id', 'changes', 'occurred_at'])]
class ReleaseActivityEvent extends Model
{
    use HasPublicId;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Release activity events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Release activity events are immutable.'));
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['changes' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
