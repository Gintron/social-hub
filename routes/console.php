<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

$tz = (string) config('hub.brand_default_timezone', 'Europe/Zagreb');

Schedule::command('hub:publish-due')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('hub:sync-sources')->hourly()->withoutOverlapping();
Schedule::command('hub:verify-accounts')->dailyAt('06:00')->timezone($tz)->withoutOverlapping();

// TikTok access tokens last a day; this keeps them ahead of expiry.
Schedule::command('hub:refresh-tiktok-tokens')->hourly()->withoutOverlapping();

// Morning pass: the agent writes captions for yesterday's new candidates and leaves them for review.
Schedule::command('hub:agent-draft')->dailyAt('07:30')->timezone($tz)->withoutOverlapping();
