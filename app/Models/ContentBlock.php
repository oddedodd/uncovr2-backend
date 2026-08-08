<?php

namespace App\Models;

use App\Enums\ContentBlockType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['page_id', 'position', 'type', 'version', 'payload', 'created_by_user_id', 'updated_by_user_id'])]
class ContentBlock extends Model
{
    use HasPublicId, SoftDeletes;

    protected $attributes = ['version' => 1];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentBlockVersion::class)->orderBy('version');
    }

    protected function casts(): array
    {
        return ['type' => ContentBlockType::class, 'version' => 'integer', 'position' => 'integer', 'payload' => 'array'];
    }
}
