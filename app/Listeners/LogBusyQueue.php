<?php

namespace App\Listeners;

use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Log;

final class LogBusyQueue
{
    public function handle(QueueBusy $event): void
    {
        Log::warning('Queue backlog threshold exceeded.', [
            'event' => 'queue.busy',
            'connection' => $event->connectionName,
            'queue' => $event->queue,
            'size' => $event->size,
            'threshold' => config('queue.monitoring.max_jobs'),
        ]);
    }
}
