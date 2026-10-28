<?php

use App\Authorization\Permissions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('permissions:sync', function () {
    Permissions::sync();

    $this->info('Đã đồng bộ '.count(Permissions::all()).' quyền.');
})->purpose('Create permission rows for every module (run after updates)');
