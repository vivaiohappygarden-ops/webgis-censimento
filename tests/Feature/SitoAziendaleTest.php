<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Support\SitoDati;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Il sito aziendale (direzione grafica "Impatto", 19/09/2026).
 *
 * Tre indirizzi convivono sullo stesso dominio: il sito che parla ai Comuni
 * (il dominio nudo), i portali civici (<comune>.…) e il gestionale
 * (gestionale.…). Qui si controlla che nessuno rubi le pagine agli altri, che
 * il sito non depositi cookie, non carichi niente da terzi e non esegua
 * script, che non stampi mai dati aziendali che non gli sono stati dati, e
 * che in collaudo resti fuori dagli indici.
 */
class SitoAziendaleTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private const BASE = 'censimentoalberature.it';

    private const PAGINE = ['censimento', 'stabilita-vta', 'portale-cittadini', 'conformita-cam', 'chi-siamo', 'contatti', 'privacy'];

    public function test_tutte_le_pagine_rispondono_dal_percorso_di_collaudo(): void
    {
        $this->get('/sito')->assertOk()->assertSee('Ogni albero identificato. Ogni controllo documentato.', false);

        foreach (self::PAGINE as $pagina) {
            $this->get('/sito/'.$pagina)->assertOk();
        }

        // Una pagina che non esiste resta un 404, non un errore del programma
        $this->get('/sito/inventata')->assertNotFound();
    }

    public function test_i_vecchi_indirizzi_rinviano_ai_nuovi(): void
    {
        $this->get('/sito/stabilita')->assertRedirect('/sito/stabilita-vta')->assertStatus(301);
        $this->get('/sito/portale')->assertRedirect('/sito/portale-cittadini');
        $this->get('/sito/conformita')->assertRedirect('/sito/conformita-cam');
    }

    public function test_il_sito_non_lascia_cookie_e_non_esegue_script(): void
    {
        // E' la ragione per cui il pie' di pagina puo' dichiarare che non c'e'
        // niente da accettare: se un giorno il sito finisse nel gruppo "web"
        // comparirebbe il cookie di sessione e la frase diventerebbe falsa
        foreach (array_merge([''], self::PAGINE) as $pagina) {
            $risposta = $this->get('/sito/'.$pagina);

            $risposta->assertOk();
            $this->assertSame([], $risposta->headers->getCookies(), "Cookie sulla pagina {$pagina}");
            $html = $risposta->getContent();
            $this->assertStringNotContainsString('<script', $html, "Script nella pagina {$pagina} (in collaudo non ci sono nemmeno i dati strutturati)");
            $this->assertStringNotContainsString('<iframe', $html);
            $this->assertStringNotContainsString('<form', $html);
            // Nessuna risorsa da altri domini: i soli "http" ammessi sono gli
            // spazi dei nomi degli SVG, che il browser non scarica
            $this->assertSame(0, preg_match_all('#(src|href)="https?://#', $html), "Risorsa esterna nella pagina {$pagina}");
            $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        }
        $this->get('/sito')->assertSee('Nessun cookie, niente da accettare', false);
    }

    public function test_i_caratteri_sono_ospitati_in_casa_e_dichiarati_con_i_pesi_veri(): void
    {
        $html = $this->get('/sito')->assertOk()->getContent();

        $this->assertStringContainsString("src: url('/portale/font/inter-tondo-latin.woff2')", $html);
        $this->assertStringContainsString("src: url('/portale/font/inter-corsivo-latin.woff2')", $html);
        $this->assertStringContainsString('font-display: swap', $html);
        $this->assertStringContainsString('font-weight: 100 900', $html);
        foreach (['inter-tondo-latin', 'inter-tondo-latin-ext', 'inter-corsivo-latin', 'inter-corsivo-latin-ext'] as $file) {
            $this->assertFileExists(public_path("portale/font/{$file}.woff2"));
        }
        $this->assertFileExists(public_path('sito-risorse/favicon.svg'));
        $this->assertFileExists(public_path('sito-risorse/anteprima.png'));
    }

    public function test_in_collaudo_le_pagine_non_si_indicizzano_e_non_hanno_canonical(): void
    {
        $html = $this->get('/sito/censimento')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('og:url', $html);
        // I collegamenti restano nel percorso di collaudo
        $this->assertStringContainsString('href="/sito/contatti"', $html);
        $this->assertStringNotContainsString('href="/contatti"', $html);
        // La mappa del sito non esiste in collaudo
        $this->get('/sito/sitemap.xml')->assertNotFound();
    }

    public function test_ogni_pagina_ha_titolo_descrizione_e_un_solo_h1(): void
    {
        $titoli = [];
        foreach (array_merge([''], self::PAGINE) as $pagina) {
            $html = $this->get('/sito/'.$pagina)->getContent();
            preg_match('#<title>(.+?)</title>#', $html, $m);
            $titoli[] = $m[1];
            $this->assertSame(1, preg_match_all('#<h1[\s>]#', $html), "La pagina {$pagina} non ha un solo h1");
            $this->assertMatchesRegularExpression('#<meta name="description" content=".{50,}">#', $html);
            $this->assertStringContainsString('<html lang="it">', $html);
            $this->assertStringContainsString('href="#contenuto"', $html);
            if ($pagina !== '') {
                $this->assertStringContainsString('aria-label="Percorso"', $html, "Manca il percorso nella pagina {$pagina}");
            }
        }
        $this->assertSame(count($titoli), count(array_unique($titoli)), 'Due pagine hanno lo stesso titolo');
    }

    public function test_il_menu_ha_sette_pagine_e_la_privacy_sta_solo_nel_pie(): void
    {
        $html = $this->get('/sito')->assertOk()->getContent();

        // Il menu in linea (dai 1000px) e quello a scomparsa dicono la stessa cosa
        preg_match('#<nav class="menu-desktop".*?</nav>#s', $html, $inLinea);
        preg_match('#<nav class="menu-pannello.*?</nav>#s', $html, $pannello);
        foreach ([$inLinea[0], $pannello[0]] as $menu) {
            foreach (['Home', 'Censimento', 'Stabilità VTA', 'Portale per i cittadini', 'Conformità CAM', 'Chi siamo', 'Contatti'] as $voce) {
                $this->assertStringContainsString('>'.$voce.'</a>', $menu);
            }
            $this->assertStringNotContainsString('Privacy', $menu);
        }
        $this->assertSame(7, substr_count($inLinea[0], '<li>'));
        // Il menu del telefono e' un details/summary: niente script
        $this->assertStringContainsString('<details class="menu-mobile">', $html);
        $this->assertStringContainsString('<summary>Menu</summary>', $html);
        // La privacy si raggiunge dal pie'
        preg_match('#<footer.*</footer>#s', $html, $piede);
        $this->assertStringContainsString('Privacy e note legali', $piede[0]);
    }

    public function test_i_dati_societari_verificati_compaiono_dove_servono(): void
    {
        // I valori di serie sono quelli forniti dal committente il 19/09/2026
        $piede = $this->get('/sito')->assertOk()->getContent();
        foreach (['DAMA S.R.L.', 'Via Crescenzio 58, 00193 Roma (RM), Italia', 'Codice fiscale e partita IVA 17947161000', 'Registro delle Imprese di Roma', 'REA RM-1751463', 'Capitale sociale € 1.000,00 sottoscritto', 'mailto:dama25@pec.it'] as $dato) {
            $this->assertStringContainsString($dato, $piede, "Manca \"{$dato}\" nel pie'");
        }
        $this->assertStringNotContainsString('versato', $piede);

        $this->get('/sito/chi-siamo')->assertOk()
            ->assertSee('DAMA S.R.L.', false)
            ->assertSee('17947161000', false)
            ->assertSee('RM-1751463', false)
            ->assertSee('Definire prima il dato da raccogliere', false);

        $this->get('/sito/contatti')->assertOk()
            ->assertSee('mailto:dama25@pec.it', false)
            ->assertDontSee('tel:', false);

        // Il titolare del trattamento nell'informativa e' la stessa impresa
        $this->get('/sito/privacy')->assertOk()->assertSee('DAMA S.R.L.', false)->assertSee('dama25@pec.it', false);
    }

    public function test_senza_dati_aziendali_non_si_stampa_niente_di_finto(): void
    {
        config(['sito.azienda' => [
            'ragione_sociale' => '', 'sede' => '  ', 'codice_fiscale' => '', 'piva' => '', 'registro_imprese' => '',
            'rea' => '', 'capitale_sociale' => '', 'territorio' => '', 'esperienza' => '',
        ], 'sito.contatti' => [
            'telefono' => '', 'email' => '', 'pec' => '', 'orari' => '', 'indirizzo' => '',
        ]]);

        $chiSiamo = $this->get('/sito/chi-siamo')->assertOk();
        foreach (['Partita IVA', 'Codice fiscale', 'Sede legale', 'REA', 'Dati societari', 'da definire', 'prossimamente', 'DA COMPILARE'] as $vuoto) {
            $chiSiamo->assertDontSee($vuoto, false);
        }
        // Quello che e' vero comunque resta: come si lavora
        $chiSiamo->assertSee('Verificare prima di consegnare', false);

        $contatti = $this->get('/sito/contatti')->assertOk();
        $contatti->assertDontSee('mailto:', false);
        $contatti->assertDontSee('tel:', false);
        $contatti->assertDontSee('<dl class="recapiti"', false);
        // I tre passaggi restano
        $contatti->assertSee('Preventivo tecnico ed economico', false);

        // Il pie' tiene solo il marchio; nessuna etichetta vuota, nessun trattino
        $html = $this->get('/sito')->assertOk()->getContent();
        $this->assertStringNotContainsString('Sede legale:', $html);
        $this->assertStringNotContainsString('<h2>Contatti</h2>', $html);
        $this->assertStringNotContainsString('Titolare del trattamento', $this->get('/sito/privacy')->getContent());
    }

    public function test_i_valori_di_soli_spazi_e_gli_elenchi_vuoti_non_contano(): void
    {
        config(['sito.contatti.telefono' => '   ', 'sito.professionisti' => ['', '  '], 'sito.referenze' => [['ente' => ' ', 'anno' => '']]]);

        $this->assertNull(SitoDati::testo('contatti.telefono'));
        $this->assertSame([], SitoDati::elenco('professionisti'));
        $this->assertSame([], SitoDati::elenco('referenze'));
        $this->get('/sito/chi-siamo')->assertOk()->assertDontSee('Referenze', false)->assertDontSee('Professionisti incaricati', false);
    }

    public function test_le_voci_facoltative_compaiono_solo_se_compilate(): void
    {
        config([
            'sito.azienda.territorio' => 'Lazio e regioni vicine',
            'sito.azienda.esperienza' => 'Rilievi del verde dal 2019',
            'sito.professionisti' => ['dott. agr. Nome Cognome, Ordine di Roma n. 1'],
            'sito.referenze' => [['ente' => 'Comune di Esempio', 'lavoro' => 'Censimento delle alberature stradali', 'anno' => '2025']],
            'sito.lavori' => [['titolo' => 'Censimento del parco comunale', 'descrizione' => 'Rilievo e cartellinatura', 'anno' => '2025']],
            'sito.perizie.firmatario' => 'dott. agr. Nome Cognome',
            'sito.perizie.titolo' => 'Dottore agronomo',
        ]);

        $this->get('/sito/chi-siamo')->assertOk()
            ->assertSee('Lazio e regioni vicine', false)
            ->assertSee('Rilievi del verde dal 2019', false)
            ->assertSee('Professionisti incaricati', false)
            ->assertSee('Comune di Esempio', false)
            ->assertSee('Censimento del parco comunale', false);
        $this->get('/sito/contatti')->assertOk()->assertSee('Territorio servito', false)->assertSee('Lazio e regioni vicine', false);
        $this->get('/sito/stabilita-vta')->assertOk()
            ->assertSee('sottoscritta da dott. agr. Nome Cognome, Dottore agronomo', false)
            ->assertDontSee('professionista incaricato', false);
    }

    public function test_senza_firmatario_la_pagina_vta_usa_la_formula_prudente(): void
    {
        config(['sito.perizie.firmatario' => '', 'sito.perizie.titolo' => '']);

        $this->get('/sito/stabilita-vta')->assertOk()
            ->assertSee("La documentazione tecnica viene sottoscritta dal professionista incaricato, secondo la natura dell'attività e le competenze richieste.", false)
            ->assertSee('Non elimina il rischio', false)
            ->assertSee('Una valutazione di stabilità non è una certificazione', false);
    }

    public function test_il_portale_di_esempio_compare_solo_se_indicato(): void
    {
        config(['sito.portale_esempio.url' => '', 'sito.portale_esempio.nome' => 'del Comune di Mentana']);
        $this->get('/sito')->assertOk()->assertDontSee('Apri il portale', false)->assertDontSee('Mentana', false);
        $this->get('/sito/portale-cittadini')->assertOk()->assertDontSee('Apri un portale vero', false);

        config(['sito.portale_esempio.url' => 'https://mentana.'.self::BASE]);
        $this->get('/sito')->assertOk()
            ->assertSee('Apri il portale', false)
            ->assertSee('del Comune di Mentana', false);
        $this->get('/sito/portale-cittadini')->assertOk()->assertSee('Apri un portale vero', false);
    }

    public function test_la_pagina_del_portale_dichiara_le_stime_come_stime(): void
    {
        $this->get('/sito/portale-cittadini')->assertOk()
            ->assertSee('I dati sui benefici ambientali sono stime calcolate secondo la metodologia indicata nel portale. Non costituiscono misurazioni dirette.', false)
            ->assertSee('Nessun cookie', false);
        // Il testo di conformita' non promette un formato unico ne' una conformita' assoluta
        $conformita = preg_replace('/\s+/', ' ', $this->get('/sito/conformita-cam')->assertOk()->getContent());
        $this->assertStringContainsString('Il tracciato di consegna viene adeguato alle specifiche tecniche del capitolato e ai requisiti applicabili alla singola procedura.', $conformita);
        $this->assertStringContainsString('Il dato è del Comune', $conformita);
        $this->assertStringContainsString('Non esiste un unico formato valido per tutti i capitolati', $conformita);
    }

    public function test_la_configurazione_di_serie_non_ha_problemi_e_quelli_veri_si_vedono(): void
    {
        $this->assertSame([], SitoDati::problemi());

        config(['sito.base_host' => 'https://www.Esempio.it/', 'sito.contatti.pec' => 'non-una-pec', 'sito.portale_esempio.url' => 'mentana.esempio.it', 'sito.perizie.titolo' => 'Dottore agronomo']);
        $problemi = SitoDati::problemi();
        $this->assertCount(4, $problemi, implode("\n", $problemi));
        $this->assertStringContainsString('dominio di produzione', $problemi[0]);
        $this->assertStringContainsString('PEC', $problemi[1]);
        $this->assertStringContainsString('portale di esempio', $problemi[2]);
        $this->assertStringContainsString('firmatario', $problemi[3]);
    }

    public function test_sul_dominio_nudo_risponde_il_sito_e_non_il_gestionale(): void
    {
        [$organizzazione] = $this->createTenantUser();
        $this->accendiPortale($organizzazione, 'mentana');

        config([
            'sito.base_host' => self::BASE,
            'portal.base_host' => self::BASE,
            'app.url' => 'https://gestionale.'.self::BASE,
        ]);
        $this->registraRotte();

        // Il dominio nudo: il sito aziendale, indicizzabile e con la canonical
        $html = $this->get('https://'.self::BASE.'/')->assertOk()
            ->assertSee('Ogni albero identificato. Ogni controllo documentato.', false)->getContent();
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://'.self::BASE.'/">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://'.self::BASE.'/sito-risorse/anteprima.png">', $html);
        $this->assertStringContainsString('href="/contatti"', $html);
        $this->assertStringNotContainsString('href="/sito/contatti"', $html);

        $interna = $this->get('https://'.self::BASE.'/censimento')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://'.self::BASE.'/censimento">', $interna);
        // Dati strutturati prudenti: organizzazione e briciole, niente valutazioni
        $this->assertStringContainsString('"@type":"Organization"', $interna);
        $this->assertStringContainsString('"vatID":"17947161000"', $interna);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $interna);
        $this->assertStringNotContainsString('aggregateRating', $interna);
        $this->assertStringNotContainsString('"review"', $interna);

        // Il www rinvia al dominio nudo, stesso percorso: una versione sola
        $this->get('https://www.'.self::BASE.'/censimento')->assertRedirect('https://'.self::BASE.'/censimento')->assertStatus(301);

        // Il sottodominio di un Comune: il suo portale civico, non il sito
        $this->get('https://mentana.'.self::BASE.'/')->assertOk()
            ->assertDontSee('Richiedi un sopralluogo', false);

        // Il nome del gestionale: il programma di gestione, che chiede l'accesso
        $this->get('https://gestionale.'.self::BASE.'/')->assertRedirectContains('/login');
    }

    public function test_sul_dominio_ci_sono_robots_e_mappa_del_sito(): void
    {
        config(['sito.base_host' => self::BASE, 'app.url' => 'https://gestionale.'.self::BASE]);
        $this->registraRotte();

        $robots = $this->get('https://'.self::BASE.'/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();
        $this->assertStringContainsString('Sitemap: https://'.self::BASE.'/sitemap.xml', $robots);
        $this->assertStringContainsString('Disallow: /sito/', $robots);

        $mappa = $this->get('https://'.self::BASE.'/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>https://'.self::BASE.'/</loc>', $mappa);
        $this->assertStringContainsString('<loc>https://'.self::BASE.'/privacy</loc>', $mappa);
        $this->assertSame(8, substr_count($mappa, '<url>'));
        $this->assertStringNotContainsString('/sito/', $mappa);

        // Sugli altri nomi il robots non vieta niente, come il vecchio file
        // statico, e tiene fuori solo il percorso di collaudo
        $altro = $this->get('https://gestionale.'.self::BASE.'/robots.txt')->assertOk()->getContent();
        $this->assertStringNotContainsString('Sitemap', $altro);
        $this->assertStringContainsString('Disallow: /sito/', $altro);
    }

    public function test_senza_dominio_la_radice_non_pubblica_il_sito(): void
    {
        // Finche' SITO_BASE_HOST e' vuoto le rotte per dominio non esistono,
        // nemmeno con il dominio dei portali configurato: la radice resta al
        // gestionale (che chiede l'accesso) e il sito si guarda solo da /sito
        config(['sito.base_host' => '', 'portal.base_host' => self::BASE, 'app.url' => 'https://gestionale.'.self::BASE]);
        $this->registraRotte();

        $this->get('https://'.self::BASE.'/')->assertRedirectContains('/login');
        $this->get('https://'.self::BASE.'/sitemap.xml')->assertNotFound();
        $this->get('/sito')->assertOk()->assertSee('noindex', false);
    }

    public function test_le_pagine_del_gestionale_non_vengono_coperte(): void
    {
        config(['sito.base_host' => self::BASE, 'app.url' => 'https://gestionale.'.self::BASE]);
        $this->registraRotte();

        // /login esiste nel gestionale e non nel sito: sul dominio nudo non
        // deve diventare un 404
        $this->get('https://'.self::BASE.'/login')->assertOk();
    }

    private function accendiPortale(Organization $organizzazione, string $slug): void
    {
        // L'area porta con se' committente, sede e localita': prima si crea
        // il territorio, poi si accende il portale di quel committente
        $this->createArea($organizzazione);

        Client::withoutGlobalScopes()->where('tenant_id', $organizzazione->id)->firstOrFail()
            ->forceFill(['public_slug' => $slug, 'public_enabled' => true])->save();
    }

    /**
     * Le rotte per dominio si registrano solo se il dominio e' configurato,
     * cosa che all'avvio della prova non e' vera: si registrano qui, come fa
     * la prova dei sottodomini dei portali.
     */
    private function registraRotte(): void
    {
        Route::middleware('portale')->group(base_path('routes/portale.php'));
        Route::middleware('sito')->group(base_path('routes/sito.php'));
        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}
