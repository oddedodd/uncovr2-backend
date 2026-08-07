<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

trait HasPublicId
{
    use HasUlids;

    public function initializeHasPublicId(): void
    {
        $this->mergeHidden([$this->getKeyName()]);
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return [$this->getPublicIdName()];
    }

    public function getPublicIdName(): string
    {
        return 'public_id';
    }

    public function getRouteKeyName(): string
    {
        return $this->getPublicIdName();
    }
}
