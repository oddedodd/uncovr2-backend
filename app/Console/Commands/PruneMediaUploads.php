<?php

namespace App\Console\Commands;

use App\Contracts\MediaStorage;
use App\Models\MediaUpload;
use Illuminate\Console\Command;

final class PruneMediaUploads extends Command
{
    protected $signature = 'media:prune-uploads';

    protected $description = 'Remove expired and superseded objects after their retention period';

    public function handle(MediaStorage $storage): int
    {
        $expiredBefore = now();
        $supersededBefore = now()->subDays(config('media.superseded_retention_days'));
        $uploads = MediaUpload::query()->where(function ($query) use ($expiredBefore, $supersededBefore): void {
            $query->where(fn ($q) => $q->where('status', 'requested')->where('expires_at', '<', $expiredBefore))
                ->orWhere(fn ($q) => $q->where('status', 'superseded')->where('superseded_at', '<', $supersededBefore));
        })->get();
        foreach ($uploads as $upload) {
            $storage->delete($upload->bucket, $upload->object_key);
            $upload->update(['status' => 'expired']);
        }
        $this->info("Pruned {$uploads->count()} media object(s).");

        return self::SUCCESS;
    }
}
