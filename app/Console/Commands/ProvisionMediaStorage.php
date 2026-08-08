<?php

namespace App\Console\Commands;

use App\Contracts\MediaStorage;
use Illuminate\Console\Command;

final class ProvisionMediaStorage extends Command
{
    protected $signature = 'media:provision-storage {--force : Confirm external Storage changes}';

    protected $description = 'Create or update the private and public Supabase Storage buckets';

    public function handle(MediaStorage $storage): int
    {
        if (! $this->option('force')) {
            $this->error('Pass --force to confirm changes to Supabase Storage.');

            return self::FAILURE;
        }
        $storage->provisionBuckets();
        $this->info('Supabase Storage buckets are configured.');

        return self::SUCCESS;
    }
}
