<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Class calendars that run on their own: open and close years at their times. Needs the scheduler's cron line on the server.
Schedule::command('ashby:tick')->everyMinute()->withoutOverlapping();
