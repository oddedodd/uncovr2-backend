<?php

namespace App\Models;

use App\Enums\ContentBlockType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content_block_id', 'version', 'type', 'payload', 'created_by_user_id', 'created_at'])]
class ContentBlockVersion extends Model
{
    public $timestamps = false;

    public function block(): BelongsTo
    {
        return $this->belongsTo(ContentBlock::class, 'content_block_id');
    }

    protected function casts(): array
    {
        return ['type' => ContentBlockType::class, 'version' => 'integer', 'payload' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
