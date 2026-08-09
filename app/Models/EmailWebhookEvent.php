<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['svix_id', 'email_delivery_id', 'event_type', 'event_occurred_at', 'processed_at'])]
class EmailWebhookEvent extends Model
{
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(EmailDelivery::class, 'email_delivery_id');
    }

    protected function casts(): array
    {
        return [
            'event_occurred_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
