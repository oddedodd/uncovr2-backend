<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['media_id', 'generation', 'bucket', 'object_key', 'expected_mime_type', 'maximum_byte_size', 'status', 'actual_byte_size', 'actual_mime_type', 'width', 'height', 'checksum_sha256', 'requested_by_user_id', 'expires_at', 'verified_at', 'activated_at', 'superseded_at'])]
class MediaUpload extends Model
{
    use HasPublicId;

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    protected function casts(): array
    {
        return [
            'generation' => 'integer', 'maximum_byte_size' => 'integer', 'actual_byte_size' => 'integer',
            'width' => 'integer', 'height' => 'integer', 'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime',
        ];
    }
}
