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
