<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('forms:retry-mail')->everyMinute()->withoutOverlapping();
Schedule::command('queue:work password-recovery --queue=password-recovery --stop-when-empty --max-time=50 --timeout=30 --tries=5')
    ->everyMinute()->withoutOverlapping();
