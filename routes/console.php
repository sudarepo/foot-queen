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

/*
 * Chaturbate's affiliate stats are daily-granularity and Chaturbate updates
 * them a handful of times a day at most — hourly is frequent enough to feel
 * near-live on the admin dashboard without hammering an endpoint that has
 * nothing new to say between updates.
 */
Schedule::command('chaturbate:sync-stats')->hourly()->withoutOverlapping(10);

/*
 * Sites record their Forge status as they're provisioned; this catches what
 * changed in Forge directly — a domain removed, a certificate expired.
 */
Schedule::command('sites:check-forge-status')->weekly()->withoutOverlapping(30);

/*
 * A site whose Forge setup failed or stalled — an API error, a refused
 * certificate — is retried a few at a time each hour until it's done.
 */
Schedule::command('sites:retry-forge-setup')->hourly()->withoutOverlapping(30);

/*
 * There's no queue daemon on the server, so the scheduler drains the queue
 * once a minute. It matters for the Forge sync above all: run from a web
 * request, the PHP-FPM reloads each new certificate triggers kill the sync
 * part-way through, while a CLI worker survives them. Overlap expires at 16
 * minutes, just past the longest job (the 15-minute Forge sync).
 */
Schedule::command('queue:work --stop-when-empty --max-time=840 --timeout=900')
    ->everyMinute()
    ->withoutOverlapping(16)
    ->runInBackground();
