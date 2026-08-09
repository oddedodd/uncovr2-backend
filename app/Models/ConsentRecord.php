<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['user_id', 'purpose', 'granted', 'policy_version', 'source', 'ip_address_hash', 'recorded_at'])]
class ConsentRecord extends Model
{
    use HasPublicId;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consent records are immutable.'));
        static::deleting(fn () => throw new LogicException('Consent records are immutable.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['granted' => 'boolean', 'recorded_at' => 'immutable_datetime'];
    }
}
