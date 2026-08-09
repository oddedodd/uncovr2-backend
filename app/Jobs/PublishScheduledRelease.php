<?php

namespace App\Jobs;

use App\Models\Release;
use App\Models\User;
use App\Services\Releases\ReleasePublicationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PublishScheduledRelease implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $releaseId, public readonly int $actorId)
    {
        $this->afterCommit();
        $this->onQueue(config('queue.channels.publishing'));
    }

    public function uniqueId(): string
    {
        return (string) $this->releaseId;
    }

    public function handle(ReleasePublicationService $service): void
    {
        $release = Release::query()->find($this->releaseId);
        $actor = User::query()->find($this->actorId);
        if (! $release || ! $actor || $release->status !== 'scheduled' || $release->scheduled_for?->isFuture()) {
            return;
        }
        $service->publish($release, $actor);
    }
}
