<?php

namespace App\Console\Commands;

use App\Models\DeviceSession;
use Illuminate\Console\Command;

class PruneDeviceSessions extends Command
{
    protected $signature = 'auth:prune-device-sessions';

    protected $description = 'Delete device sessions after their security retention period';

    public function handle(): int
    {
        $cutoff = now()->subDays(config('authentication.refresh_token_retention_days'));
        $deleted = 0;

        DeviceSession::query()
            ->where('absolute_expires_at', '<=', $cutoff)
            ->chunkById(100, function ($sessions) use (&$deleted): void {
                foreach ($sessions as $session) {
                    $session->delete();
                    $deleted++;
                }
            });

        $this->info("Pruned {$deleted} expired device session(s).");

        return self::SUCCESS;
    }
}
