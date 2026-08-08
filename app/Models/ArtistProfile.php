<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'biography', 'website_url'])]
class ArtistProfile extends Model
{
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
