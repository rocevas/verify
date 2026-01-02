<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('disposable:update')->daily();

// Check monitors every 5 minutes
Schedule::command('monitors:check')->everyFiveMinutes();

// Clean up expired MX skip list entries daily
Schedule::command('email:cleanup-mx-skip-list')->daily();
