<?php

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RecordQueueHeartbeat)
    ->everyMinute();

Schedule::command('queue:work database --stop-when-empty --max-time=50 --tries=4 --timeout=300')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('destinations:recover-stale-imports')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('destinations:prewarm')
    ->dailyAt('02:30')
    ->withoutOverlapping(60);

Schedule::command('destinations:retry-enrichment')
    ->dailyAt('03:00')
    ->withoutOverlapping(60);
