<?php

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ops:backup')->dailyAt('02:00')->withoutOverlapping()
    ->when(fn (): bool => config('database.default') === 'sqlite');
Schedule::call(fn () => Cache::put('health:scheduler:last_seen', now()->timestamp, now()->addMinutes(10)))
    ->name('scheduler-heartbeat')->everyMinute()->withoutOverlapping();
Schedule::job(new RecordQueueHeartbeat)->name('queue-heartbeat')->everyMinute()->withoutOverlapping();
