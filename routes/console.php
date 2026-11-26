<?php

use App\Ashby\Reporting\ReportingViews;
use App\Models\ReportingCall;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Class calendars that run on their own: open and close years at their times. Needs the scheduler's cron line on the server.
Schedule::command('ashby:tick')->everyMinute()->withoutOverlapping();

// Reminders before a year closes, while outgoing mail is on.
Schedule::command('ashby:remind')->everyFiveMinutes()->withoutOverlapping();

// A heartbeat each minute, so the reporting connector shows when the cron line has stopped.
Schedule::call(fn () => Cache::forever(ReportingViews::SCHEDULER_KEY, now()->getTimestamp()))->everyMinute()->name('ashby:scheduler-heartbeat');

Artisan::command('ashby:reporting-tidy', function () {
    $expires = ReportingViews::checkCertificate();
    $this->info($expires ? 'Certificate expires '.date('Y-m-d', $expires).'.' : 'Certificate not checked (APP_URL isn\'t https, or the site didn\'t answer).');
    $codes = DB::table('reporting_codes')->where('expires_at', '<', now()->subDay())->delete();
    $calls = ReportingCall::query()->where('created_at', '<', now()->subDays(90))->delete();
    $this->info("Removed {$codes} old sign-in codes and {$calls} call logs over 90 days old.");
})->purpose('Check the site certificate and tidy the reporting connector\'s logs');

Schedule::command('ashby:reporting-tidy')->dailyAt('04:10');
