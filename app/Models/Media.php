<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'artist_id', 'kind', 'status', 'original_filename', 'mime_type', 'byte_size', 'width', 'height', 'storage_disk', 'storage_key', 'metadata', 'created_by_user_id', 'updated_by_user_id'])]
class Media extends Model
{
    use HasPublicId, SoftDeletes;

    protected $table = 'media';

    protected $attributes = ['status' => 'pending'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function releasesAsCover(): HasMany
    {
        return $this->hasMany(Release::class, 'cover_media_id');
    }

    protected function casts(): array
    {
        return ['byte_size' => 'integer', 'width' => 'integer', 'height' => 'integer', 'metadata' => 'array'];
    }
}
