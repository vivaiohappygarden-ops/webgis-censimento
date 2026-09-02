<?php

use App\Http\Controllers\Web\CalendarioFeedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Calendario da abbonamento (feed iCal)
|--------------------------------------------------------------------------
|
| Un solo indirizzo, riconosciuto dal gettone personale: ci si abbona da
| Google Calendar, iPhone o Outlook e l'agenda dei lavori e delle scadenze
| arriva sul telefono senza aprire il gestionale.
|
| Il gruppo di middleware "calendario" (bootstrap/app.php) non avvia la
| sessione e non lascia cookie: i lettori di calendari sono programmi, non
| browser. Il vincolo sul gettone scarta subito gli indirizzi malformati.
|
*/

Route::get('/calendario/{gettone}.ics', [CalendarioFeedController::class, 'feed'])
    ->where('gettone', '[A-Za-z0-9_-]{40,100}')
    ->name('calendario.feed');
