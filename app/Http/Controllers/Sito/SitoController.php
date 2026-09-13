<?php

namespace App\Http\Controllers\Sito;

use App\Http\Controllers\Controller;
use App\Support\SitoUrl;
use Illuminate\Contracts\View\View;

/**
 * Il sito aziendale: pagine di sola lettura, contenuti dalla configurazione.
 *
 * Non c'e' nessun dato del database qui dentro, e non c'e' nessun modulo che
 * raccolga qualcosa: e' un sito di presentazione, e tenerlo senza stato e'
 * quello che gli permette di girare senza sessione, senza cookie e senza
 * niente da far accettare a chi lo apre.
 */
class SitoController extends Controller
{
    /** Le pagine del sito: rotta -> vista. Aggiungerne una si fa qui e in routes/sito.php. */
    private const PAGINE = [
        'home' => 'sito.home',
        'censimento' => 'sito.censimento',
        'stabilita' => 'sito.stabilita',
        'portale' => 'sito.portale',
        'conformita' => 'sito.conformita',
        'chi-siamo' => 'sito.chi-siamo',
        'contatti' => 'sito.contatti',
        'privacy' => 'sito.privacy',
    ];

    public function pagina(string $quale = 'home'): View
    {
        abort_unless(isset(self::PAGINE[$quale]), 404);

        // Ogni vista riceve il costruttore di indirizzi: i collegamenti
        // restano nella strada da cui si e' arrivati (dominio o /sito)
        return view(self::PAGINE[$quale], [
            'u' => fn (string $pagina = '') => SitoUrl::per($pagina),
        ]);
    }
}
