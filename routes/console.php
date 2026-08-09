<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->withoutOverlapping();

Schedule::command('auth:prune-device-sessions')
    ->daily()
    ->withoutOverlapping();

Schedule::command('auth:clear-resets')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('releases:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('media:prune-uploads')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('privacy:process-account-deletions')
    ->daily()
    ->withoutOverlapping();

$monitoredQueues = collect(config('queue.channels'))
    ->filter(fn (mixed $queue): bool => is_string($queue) && $queue !== '')
    ->unique()
    ->map(fn (string $queue): string => config('queue.default').':'.$queue)
    ->implode(',');

Schedule::command('queue:monitor', [
    $monitoredQueues,
    '--max' => config('queue.monitoring.max_jobs'),
])->everyMinute()->withoutOverlapping();

Schedule::command('queue:prune-failed', [
    '--hours' => config('queue.monitoring.failed_retention_hours'),
])->daily()->withoutOverlapping();

Schedule::command('queue:prune-batches', [
    '--hours' => config('queue.monitoring.batch_retention_hours'),
    '--unfinished' => config('queue.monitoring.batch_retention_hours'),
    '--cancelled' => config('queue.monitoring.batch_retention_hours'),
])->daily()->withoutOverlapping();

Schedule::command('operations:check')
    ->everyFiveMinutes()
    ->withoutOverlapping();
