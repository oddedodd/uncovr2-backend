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
