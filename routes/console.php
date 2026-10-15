<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Preços do Google Shopping 4x ao dia (2 créditos Serper por produto a cada rodada).
// pricewatch:discover (Tavily) e pricewatch:collect (raspagem das lojas) saíram do agendamento
// em 2026-10-14: davam preços fantasmas e 403; seguem disponíveis para uso manual.
Schedule::command('pricewatch:shopping')
    ->cron('0 8,12,16,20 * * *')
    ->withoutOverlapping()
    ->runInBackground();
