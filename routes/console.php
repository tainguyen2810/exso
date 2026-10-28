<?php

use App\Authorization\Permissions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('permissions:sync', function () {
    Permissions::sync();

    $this->info('Đã đồng bộ '.count(Permissions::all()).' quyền.');
})->purpose('Create permission rows for every module (run after updates)');

// Hosting without Supervisor: the cron that runs schedule:run also works
// through the queue (report refreshes, mail...) for up to 50 seconds.
if (config('queue.default') !== 'sync') {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
        ->everyMinute()
        ->withoutOverlapping(5);
}
