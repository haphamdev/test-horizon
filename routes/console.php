<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Horizon metrics graphs are built from periodic snapshots. One-minute cadence
// keeps the demo's Metrics pages populating quickly (needs >=2 snapshots).
Schedule::command('horizon:snapshot')->everyMinute();
