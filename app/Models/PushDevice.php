<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'device_session_id', 'platform', 'token_hash', 'push_token', 'enabled_at', 'disabled_at', 'last_seen_at'])]
class PushDevice extends Model
{
    use HasPublicId;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deviceSession(): BelongsTo
    {
        return $this->belongsTo(DeviceSession::class);
    }

    protected function casts(): array
    {
        return ['push_token' => 'encrypted', 'enabled_at' => 'immutable_datetime', 'disabled_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
