<?php

namespace App\Console\Commands;

use App\Services\Privacy\AccountDeletionService;
use Illuminate\Console\Command;

final class ProcessAccountDeletions extends Command
{
    protected $signature = 'privacy:process-account-deletions';

    protected $description = 'Anonymize accounts whose deletion grace period has expired';

    public function handle(AccountDeletionService $deletions): int
    {
        $count = $deletions->processDue();
        $this->info("Processed {$count} account deletion(s).");

        return self::SUCCESS;
    }
}
