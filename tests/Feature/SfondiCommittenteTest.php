<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Sfondi cartografici per committente: ortofoto comunale, carta tecnica,
 * servizi WMS. Le voci si salvano sul committente e diventano modelli di
 * riquadri pronti per la mappa del gestionale e per il portale pubblico.
 */
class SfondiCommittenteTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    private Client $committente;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        // createArea costruisce anche la filiera committente -> sede -> localita'
        $this->area = $this->createArea($this->organizzazione);
        $this->committente = Client::withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)->firstOrFail();
        $this->actingAsTenantUser($this->utente);
    }

    public function test_uno_sfondo_a_riquadri_si_salva_e_torna_pronto(): void
    {
        $this->patchJson("/api/v1/clients/{$this->committente->id}", [
            'basemaps' => [[
                'nome' => 'Ortofoto comunale', 'tipo' => 'xyz',
                'url' => 'https://tile.comune.example/orto/{z}/{x}/{y}.png',
                'attribuzione' => 'Comune di Esempio',
            ]],
        ])->assertOk();

        $this->getJson("/api/v1/clients/{$this->committente->id}/sfondi")
            ->assertOk()
            ->assertJsonPath('data.0.id', 'committente-0')
            ->assertJsonPath('data.0.nome', 'Ortofoto comunale')
            ->assertJsonPath('data.0.url', 'https://tile.comune.example/orto/{z}/{x}/{y}.png')
            ->assertJsonPath('data.0.attribuzione', 'Comune di Esempio');
    }

    public function test_il_wms_diventa_una_chiamata_getmap_su_riquadri(): void
    {
        $this->patchJson("/api/v1/clients/{$this->committente->id}", [
            'basemaps' => [[
                'nome' => 'Carta tecnica', 'tipo' => 'wms',
                'url' => 'https://wms.regione.example/servizio',
                'layer' => 'ctr_2024',
            ]],
        ])->assertOk();

        $url = $this->getJson("/api/v1/clients/{$this->committente->id}/sfondi")
            ->assertOk()->json('data.0.url');

        // La chiamata GetMap si costruisce dal programma, in un solo posto:
        // versione 1.1.1, EPSG:3857, riquadri da 256 pixel, col segnaposto
        // del riquadro NON codificato (lo sostituisce la mappa)
        $this->assertStringStartsWith('https://wms.regione.example/servizio?', $url);
        $this->assertStringContainsString('SERVICE=WMS', $url);
        $this->assertStringContainsString('VERSION=1.1.1', $url);
        $this->assertStringContainsString('REQUEST=GetMap', $url);
        $this->assertStringContainsString('LAYERS=ctr_2024', $url);
        $this->assertStringContainsString('SRS=EPSG%3A3857', $url);
        $this->assertStringContainsString('WIDTH=256', $url);
        $this->assertStringEndsWith('&BBOX={bbox-epsg-3857}', $url);
    }

    public function test_gli_indirizzi_sbagliati_si_rifiutano(): void
    {
        // Senza https la pagina (in https) non caricherebbe le immagini
        $this->patchJson("/api/v1/clients/{$this->committente->id}", [
            'basemaps' => [['nome' => 'X', 'tipo' => 'xyz', 'url' => 'http://tile.example/{z}/{x}/{y}.png']],
        ])->assertStatus(422)->assertJsonValidationErrors(['basemaps.0.url']);

        // Un modello a riquadri senza segnaposto non disegna niente
        $this->patchJson("/api/v1/clients/{$this->committente->id}", [
            'basemaps' => [['nome' => 'X', 'tipo' => 'xyz', 'url' => 'https://tile.example/orto.png']],
        ])->assertStatus(422)->assertJsonValidationErrors(['basemaps.0.url']);

        // Il WMS senza nome del livello non sa cosa chiedere
        $this->patchJson("/api/v1/clients/{$this->committente->id}", [
            'basemaps' => [['nome' => 'X', 'tipo' => 'wms', 'url' => 'https://wms.example/s']],
        ])->assertStatus(422)->assertJsonValidationErrors(['basemaps.0.layer']);

        // Piu' di sei sfondi non servono e appesantiscono il selettore
        $troppi = array_fill(0, 7, ['nome' => 'X', 'tipo' => 'xyz', 'url' => 'https://t.example/{z}/{x}/{y}.png']);
        $this->patchJson("/api/v1/clients/{$this->committente->id}", ['basemaps' => $troppi])
            ->assertStatus(422)->assertJsonValidationErrors(['basemaps']);
    }

    public function test_la_mappa_pubblica_elenca_anche_gli_sfondi_del_comune(): void
    {
        $this->committente->forceFill([
            'public_slug' => 'mentana', 'public_enabled' => true, 'label_prefix' => 'MEN',
            'basemaps' => [[
                'nome' => 'Ortofoto comunale', 'tipo' => 'xyz',
                'url' => 'https://tile.comune.example/orto/{z}/{x}/{y}.png',
            ]],
        ])->save();

        // Serve almeno un elemento pubblicato, o la mappa dice che e' vuota
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $tipo->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated();

        $this->get('/comune/mentana/mappa')
            ->assertOk()
            ->assertSee('Ortofoto comunale')
            // Gli sfondi di serie restano al loro posto
            ->assertSee('Stradale');
    }

    public function test_gli_sfondi_di_un_altra_impresa_non_si_leggono(): void
    {
        [, $altro] = $this->createTenantUser();
        $this->actingAsTenantUser($altro);

        $this->getJson("/api/v1/clients/{$this->committente->id}/sfondi")->assertNotFound();
    }

    public function test_senza_permesso_sui_committenti_niente_sfondi(): void
    {
        $senzaRuoli = \App\Models\User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        $this->actingAsTenantUser($senzaRuoli);

        $this->getJson("/api/v1/clients/{$this->committente->id}/sfondi")->assertForbidden();
    }
}
