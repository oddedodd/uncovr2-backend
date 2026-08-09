<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'topic', 'email_enabled', 'push_enabled', 'in_app_enabled'])]
class NotificationPreference extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['email_enabled' => 'boolean', 'push_enabled' => 'boolean', 'in_app_enabled' => 'boolean'];
    }
}
