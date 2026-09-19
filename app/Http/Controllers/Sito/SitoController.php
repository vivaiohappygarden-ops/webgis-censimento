<?php

namespace App\Http\Controllers\Sito;

use App\Http\Controllers\Controller;
use App\Support\SitoUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Il sito aziendale: otto pagine di sola lettura, contenuti dalla configurazione.
 *
 * Non c'e' nessun dato del database qui dentro, nessun modulo, nessuno
 * script: e' un sito di presentazione, e tenerlo senza stato e' quello che
 * gli permette di girare senza sessione, senza cookie e senza niente da far
 * accettare a chi lo apre.
 */
class SitoController extends Controller
{
    /**
     * Le otto pagine, nell'ordine del menu. `menu` e' l'etichetta nella
     * testata: sette pagine (null = solo nel pie', come la privacy). Titolo e descrizione
     * sono quelli del <title> e della meta description: unici per pagina.
     */
    public const PAGINE = [
        'home' => [
            'vista' => 'sito.home',
            'titolo' => 'Censimento, VTA e portale pubblico per i Comuni',
            'descrizione' => 'Censimento, cartellinatura, valutazione di stabilità VTA e consegna dei dati al Comune, con il portale pubblico del patrimonio arboreo. Ogni albero diventa un dato verificabile, aggiornabile e di proprietà dell\'ente.',
            'breadcrumb' => 'Home',
            'menu' => 'Home',
        ],
        'censimento' => [
            'vista' => 'sito.censimento',
            'titolo' => 'Censimento delle alberature',
            'descrizione' => 'Come si svolge il censimento del patrimonio arboreo: definizione dell\'ambito, rilievo in campo, cartellinatura, verifica e consegna. Che cosa si registra per ogni albero, secondo il capitolato.',
            'breadcrumb' => 'Censimento',
            'menu' => 'Censimento',
        ],
        'stabilita-vta' => [
            'vista' => 'sito.stabilita',
            'titolo' => 'Valutazione di stabilità VTA',
            'descrizione' => 'Valutazione visiva di stabilità degli alberi: osservazioni in campo, eventuali approfondimenti strumentali, documentazione sottoscritta, scadenzario dei ricontrolli e tracciabilità delle verifiche.',
            'breadcrumb' => 'Stabilità VTA',
            'menu' => 'Stabilità VTA',
        ],
        'portale-cittadini' => [
            'vista' => 'sito.portale',
            'titolo' => 'Portale pubblico per i cittadini',
            'descrizione' => 'Il portale pubblico del patrimonio arboreo: mappa, scheda di ogni albero, ricerca per numero del cartellino, codice QR. Senza profilazione e senza cookie, con i dati che il Comune sceglie di pubblicare.',
            'breadcrumb' => 'Portale per i cittadini',
            'menu' => 'Portale per i cittadini',
        ],
        'conformita-cam' => [
            'vista' => 'sito.conformita',
            'titolo' => 'Conformità ai capitolati e ai CAM',
            'descrizione' => 'Dati georeferenziati, identificativi univoci, formati aperti ed esportabili: la consegna si adegua alle specifiche del capitolato e ai requisiti applicabili. Il dato è del Comune.',
            'breadcrumb' => 'Conformità CAM',
            'menu' => 'Conformità CAM',
        ],
        'chi-siamo' => [
            'vista' => 'sito.chi-siamo',
            'titolo' => 'Chi siamo',
            'descrizione' => 'Chi esegue i censimenti delle alberature e le valutazioni di stabilità: i dati societari e le tre abitudini di lavoro che si vedono in quello che consegniamo.',
            'breadcrumb' => 'Chi siamo',
            'menu' => 'Chi siamo',
        ],
        'contatti' => [
            'vista' => 'sito.contatti',
            'titolo' => 'Richiedi un sopralluogo',
            'descrizione' => 'Come chiedere un sopralluogo per il censimento delle alberature e le valutazioni di stabilità: recapiti e i tre passaggi successivi, dal primo contatto al preventivo.',
            'breadcrumb' => 'Contatti',
            'menu' => 'Contatti',
        ],
        'privacy' => [
            'vista' => 'sito.privacy',
            'titolo' => 'Privacy e note legali',
            'descrizione' => 'Che cosa raccoglie questo sito: nessun modulo, nessun cookie, nessuna profilazione, nessuna risorsa da siti terzi. Registri tecnici del server e titolare del trattamento.',
            'breadcrumb' => 'Privacy e note legali',
            'menu' => null,
        ],
    ];

    /** Indirizzi della versione precedente: rispondono con un rinvio definitivo. */
    public const RINOMINATE = [
        'stabilita' => 'stabilita-vta',
        'portale' => 'portale-cittadini',
        'conformita' => 'conformita-cam',
    ];

    public function pagina(string $quale = 'home'): View
    {
        abort_unless(isset(self::PAGINE[$quale]), 404);

        $meta = self::PAGINE[$quale];

        return view($meta['vista'], [
            'pagina' => $quale,
            'meta' => $meta,
            // Ogni vista riceve il costruttore di indirizzi: i collegamenti
            // restano nella strada da cui si e' arrivati (dominio o /sito)
            'u' => fn (string $pagina = '') => SitoUrl::per($pagina),
            'menu' => array_filter(array_map(fn (array $p) => $p['menu'], self::PAGINE)),
        ]);
    }

    public function rinominata(string $vecchia): RedirectResponse
    {
        abort_unless(isset(self::RINOMINATE[$vecchia]), 404);

        return redirect()->to(SitoUrl::per(self::RINOMINATE[$vecchia]), 301);
    }

    /** www.dominio → dominio nudo, stesso percorso: una sola versione da indicizzare. */
    public function senzaWww(Request $request, ?string $percorso = null): RedirectResponse
    {
        $indirizzo = 'https://'.SitoUrl::dominio().'/'.ltrim((string) $percorso, '/');
        if ($request->getQueryString()) {
            $indirizzo .= '?'.$request->getQueryString();
        }

        return redirect()->away($indirizzo, 301);
    }

    /**
     * robots.txt per tutti i nomi serviti dall'applicazione. Sul dominio del
     * sito dichiara la mappa e tiene fuori il percorso di collaudo; altrove
     * dice quello che diceva il vecchio file statico: nessun divieto.
     */
    public function robots(Request $request): Response
    {
        $dominio = SitoUrl::dominio();
        $righe = ['User-agent: *'];

        if ($dominio !== null && in_array($request->getHost(), [$dominio, 'www.'.$dominio], true)) {
            $righe[] = 'Allow: /';
            $righe[] = 'Disallow: /sito/';
            $righe[] = '';
            $righe[] = 'Sitemap: https://'.$dominio.'/sitemap.xml';
        } else {
            $righe[] = 'Disallow: /sito/';
        }

        return response(implode("\n", $righe)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** La mappa del sito: le otto pagine sul dominio di produzione. */
    public function sitemap(): Response
    {
        $righe = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach (array_keys(self::PAGINE) as $pagina) {
            $righe[] = '  <url><loc>'.e(SitoUrl::assoluto($pagina)).'</loc></url>';
        }
        $righe[] = '</urlset>';

        return response(implode("\n", $righe)."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
