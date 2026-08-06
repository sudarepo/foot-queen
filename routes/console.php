<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cams:sync')->everyFiveMinutes();

/*
 * Profiles (bio + pics & vids) cost one rate-limited request per performer,
 * so they run on their own slower cadence rather than riding along with the
 * room sync. A default batch is ~2 minutes of paced fetching; the overlap
 * lock expires after 10 so a crashed run can't wedge the schedule for a day
 * (Laravel's default), but a slow one still can't stack up.
 */
Schedule::command('cams:sync-profiles')->everyFifteenMinutes()->withoutOverlapping(10);
