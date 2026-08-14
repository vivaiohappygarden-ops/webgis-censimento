<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Riepilogo scadenze alle 6:30 italiane: in cassetta prima dell'inizio
// della giornata di lavoro. L'esito resta nei log dell'applicazione:
// il cron dello scheduler scarta il proprio output
Schedule::command('notifications:daily')
    ->dailyAt('06:30')
    ->timezone('Europe/Rome')
    ->appendOutputTo(storage_path('logs/notifications.log'));
