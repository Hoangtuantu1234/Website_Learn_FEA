<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('engagement:process-reminders')->dailyAt('08:00');
Schedule::command('instructors:check-profile-deadlines')->dailyAt('00:00');
Schedule::command('leaderboard:reward-monthly')->monthlyOn(1, '00:05');
Schedule::command('leaderboard:reward-weekly')->weeklyOn(1, '00:05');
Schedule::command('video:recover-pending-hls')->everyFiveMinutes();
Schedule::command('registration:prune-pending-cvs')->daily();

