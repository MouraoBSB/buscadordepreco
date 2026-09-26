<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule daily collection for all active product sources without overlapping
Schedule::command('pricewatch:collect')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Schedule discovery twice a week (Tuesday & Friday at 09:00) to find new deals and stores
Schedule::command('pricewatch:discover')
    ->twiceWeekly(2, 5, '09:00')
    ->withoutOverlapping()
    ->runInBackground();

