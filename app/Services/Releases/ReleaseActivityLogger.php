<?php

namespace App\Services\Releases;

use App\Models\Release;
use App\Models\ReleaseActivityEvent;
use App\Models\User;

final class ReleaseActivityLogger
{
    public function record(Release $release, User $actor, string $eventType, ?object $subject = null, ?array $changes = null): ReleaseActivityEvent
    {
        return $release->activityEvents()->create([
            'user_id' => $actor->getKey(),
            'event_type' => $eventType,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_public_id' => $subject?->public_id,
            'changes' => $changes,
            'occurred_at' => now()->startOfSecond(),
        ]);
    }
}
