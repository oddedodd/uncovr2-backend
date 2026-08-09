<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LogFailedQueueJob
{
    public function handle(JobFailed $event): void
    {
        Log::error('Queue job failed.', [
            'event' => 'queue.job_failed',
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'job_id' => $event->job->getJobId(),
            'job_uuid' => $event->job->uuid(),
            'job' => $this->jobName($event),
            'attempts' => $event->job->attempts(),
            'exception_class' => $event->exception::class,
        ]);
    }

    private function jobName(JobFailed $event): string
    {
        try {
            return $event->job->resolveName();
        } catch (Throwable) {
            return 'unresolved';
        }
    }
}
