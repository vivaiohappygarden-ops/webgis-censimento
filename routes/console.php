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

// Lo sfondo della mappa per l'uso senza rete (dal 10/10/2026): ogni notte il
// comando rifa' gli sfondi piu' vecchi di sfondo.giorni_validita e prepara
// quelli delle organizzazioni nuove; gli altri li salta in un attimo
Schedule::command('sfondo:prepara --tutte')
    ->dailyAt('03:40')
    ->timezone('Europe/Rome')
    ->appendOutputTo(storage_path('logs/sfondo.log'));
