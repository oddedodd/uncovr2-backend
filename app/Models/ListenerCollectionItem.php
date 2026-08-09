<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['listener_collection_id', 'item_type', 'release_id', 'track_id', 'position'])]
class ListenerCollectionItem extends Model
{
    use HasPublicId;

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ListenerCollection::class, 'listener_collection_id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
