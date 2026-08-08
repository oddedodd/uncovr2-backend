<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['release_id', 'requested_by_user_id', 'status', 'content_fingerprint', 'request_note', 'decided_by_user_id', 'decision_note', 'decided_at'])]
class ReleaseApprovalRequest extends Model
{
    use HasPublicId;

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }
}
