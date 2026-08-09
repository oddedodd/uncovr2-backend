<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'status', 'requested_at', 'scheduled_for', 'cancelled_at', 'completed_at'])]
class AccountDeletionRequest extends Model
{
    use HasPublicId;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'scheduled_for' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
