<?php

namespace App\Console\Commands;

use App\Jobs\PublishScheduledRelease;
use App\Models\Release;
use Illuminate\Console\Command;

final class DispatchScheduledReleases extends Command
{
    protected $signature = 'releases:dispatch-scheduled';

    protected $description = 'Dispatch due, approved releases to the publication queue';

    public function handle(): int
    {
        $count = 0;
        Release::query()->where('status', 'scheduled')->where('scheduled_for', '<=', now())
            ->whereNotNull('published_by_user_id')->orderBy('id')->chunkById(100, function ($releases) use (&$count): void {
                foreach ($releases as $release) {
                    PublishScheduledRelease::dispatch($release->getKey(), $release->published_by_user_id);
                    $count++;
                }
            });
        $this->info("Dispatched {$count} scheduled release(s).");

        return self::SUCCESS;
    }
}
