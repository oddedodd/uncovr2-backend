<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['provider_message_id', 'status', 'last_event_at', 'terminal_at'])]
class EmailDelivery extends Model
{
    public function events(): HasMany
    {
        return $this->hasMany(EmailWebhookEvent::class);
    }

    protected function casts(): array
    {
        return [
            'last_event_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
        ];
    }
}
