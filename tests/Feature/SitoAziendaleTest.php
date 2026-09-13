<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Il sito aziendale sul dominio nudo.
 *
 * Tre indirizzi convivono sullo stesso dominio: il sito che parla ai Comuni
 * (censimentoalberature.it), i portali civici (<comune>.…) e il gestionale
 * (gestionale.…). Qui si controlla che nessuno rubi le pagine agli altri,
 * che il sito non depositi cookie e che non stampi mai dati aziendali che
 * non gli sono stati dati.
 */
class SitoAziendaleTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private const BASE = 'censimentoalberature.it';

    private const PAGINE = ['censimento', 'stabilita', 'portale', 'conformita', 'chi-siamo', 'contatti', 'privacy'];

    public function test_tutte_le_pagine_rispondono_dal_percorso_di_collaudo(): void
    {
        $this->get('/sito')->assertOk()->assertSee('Censimento e catasto del verde urbano', false);

        foreach (self::PAGINE as $pagina) {
            $this->get('/sito/'.$pagina)->assertOk();
        }

        // Una pagina che non esiste resta un 404, non un errore del programma
        $this->get('/sito/inventata')->assertNotFound();
    }

    public function test_il_sito_non_lascia_cookie(): void
    {
        // E' la ragione per cui il pie' di pagina puo' dichiarare che non c'e'
        // niente da accettare: se un giorno il sito finisse nel gruppo "web"
        // comparirebbe il cookie di sessione e la frase diventerebbe falsa
        $risposta = $this->get('/sito');

        $risposta->assertOk();
        $this->assertSame([], $risposta->headers->getCookies());
        $risposta->assertSee('non usa cookie', false);
    }

    public function test_dal_percorso_di_collaudo_i_collegamenti_restano_nel_percorso(): void
    {
        $html = $this->get('/sito')->assertOk()->getContent();

        $this->assertStringContainsString('href="/sito/contatti"', $html);
        $this->assertStringNotContainsString('href="/contatti"', $html);
    }

    public function test_senza_dati_aziendali_non_si_stampa_niente_di_finto(): void
    {
        config(['sito.azienda' => [
            'ragione_sociale' => '', 'sede' => '', 'piva' => '', 'rea' => '',
            'territorio' => '', 'esperienza' => '',
        ], 'sito.contatti' => [
            'telefono' => '', 'email' => '', 'pec' => '', 'orari' => '', 'indirizzo' => '',
        ]]);

        $chiSiamo = $this->get('/sito/chi-siamo')->assertOk();
        $chiSiamo->assertDontSee('P. IVA', false);
        $chiSiamo->assertDontSee('DA COMPILARE', false);
        // Quello che e' vero comunque resta: come si lavora
        $chiSiamo->assertSee('Si misura, non si stima', false);

        $contatti = $this->get('/sito/contatti')->assertOk();
        $contatti->assertSee('non sono ancora stati pubblicati', false);
        $contatti->assertDontSee('mailto:', false);
    }

    public function test_con_i_dati_compilati_compaiono_dove_servono(): void
    {
        config(['sito.azienda.ragione_sociale' => 'Verde Pubblico srl',
            'sito.azienda.piva' => '01234567890',
            'sito.contatti.email' => 'ufficio@example.test',
            'sito.contatti.telefono' => '06 1234567']);

        $this->get('/sito/chi-siamo')->assertOk()
            ->assertSee('Verde Pubblico srl', false)
            ->assertSee('01234567890', false);

        $this->get('/sito/contatti')->assertOk()
            ->assertSee('ufficio@example.test', false)
            ->assertSee('06 1234567', false);

        // Il titolare del trattamento nell'informativa e' la stessa impresa
        $this->get('/sito/privacy')->assertOk()->assertSee('Verde Pubblico srl', false);
    }

    public function test_il_portale_di_esempio_compare_solo_se_indicato(): void
    {
        config(['sito.portale_esempio.url' => '']);
        $this->get('/sito')->assertOk()->assertDontSee('Apri il portale', false);

        config(['sito.portale_esempio.url' => 'https://mentana.'.self::BASE,
            'sito.portale_esempio.nome' => 'del Comune di Mentana']);
        $this->get('/sito')->assertOk()
            ->assertSee('Apri il portale', false)
            ->assertSee('del Comune di Mentana', false);
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

        // Il dominio nudo: il sito aziendale
        $this->get('https://'.self::BASE.'/')->assertOk()
            ->assertSee('Censimento e catasto del verde urbano', false);
        $this->get('https://www.'.self::BASE.'/censimento')->assertOk()
            ->assertSee('Quattro fasi', false);

        // Il sottodominio di un Comune: il suo portale civico, non il sito
        $this->get('https://mentana.'.self::BASE.'/')->assertOk()
            ->assertDontSee('Richiedi un sopralluogo', false);

        // Il nome del gestionale: il programma di gestione, che chiede l'accesso
        $this->get('https://gestionale.'.self::BASE.'/')->assertRedirectContains('/login');
    }

    public function test_sul_dominio_nudo_i_collegamenti_non_portano_al_percorso_di_collaudo(): void
    {
        config(['sito.base_host' => self::BASE]);
        $this->registraRotte();

        $html = $this->get('https://'.self::BASE.'/')->assertOk()->getContent();

        $this->assertStringContainsString('href="/contatti"', $html);
        $this->assertStringNotContainsString('href="/sito/contatti"', $html);
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
