<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Dizionari agronomici dell'anagrafica albero: fase fisiologica a sei stadi,
 * posizione sociale, bersaglio, sito di crescita, qualificatore dell'eta'.
 * Le voci ammesse vivono in config/agronomia.php e da li' arrivano a tendine,
 * validazione, storico e stampe.
 */
class DizionariAgronomiciTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    private $tipoAlbero;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->tipoAlbero = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);
    }

    private function creaAlbero(): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    public function test_i_campi_agronomici_si_salvano_e_si_rileggono(): void
    {
        $id = $this->creaAlbero();

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => [
                'age_years_est' => 12,
                'age_qualifier' => 'stimata',
                'age_class' => 'giovane adulto',
                'social_position' => 'filare',
                'growth_site' => 'aiuola',
                'target' => 'frequente',
            ],
        ])->assertOk();

        $this->getJson("/api/v1/assets/{$id}")
            ->assertOk()
            ->assertJsonPath('data.tree.age_years_est', 12)
            ->assertJsonPath('data.tree.age_qualifier', 'stimata')
            ->assertJsonPath('data.tree.age_class', 'giovane adulto')
            ->assertJsonPath('data.tree.social_position', 'filare')
            ->assertJsonPath('data.tree.growth_site', 'aiuola')
            ->assertJsonPath('data.tree.target', 'frequente');
    }

    public function test_una_voce_fuori_dizionario_viene_rifiutata_col_nome_italiano(): void
    {
        $id = $this->creaAlbero();

        $risposta = $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => [
                'social_position' => 'capobranco',
                'growth_site' => 'iperspazio',
                'target' => 'ovunque',
                'age_qualifier' => 'inventata',
            ],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'tree.social_position', 'tree.growth_site', 'tree.target', 'tree.age_qualifier',
        ]);

        // Il messaggio parla della "posizione sociale", non di "tree.social position"
        $errori = $risposta->json('errors');
        $this->assertStringContainsString('posizione sociale', $errori['tree.social_position'][0]);
    }

    public function test_la_fase_fisiologica_ha_sei_stadi_e_comprende_i_quattro_storici(): void
    {
        $stadi = config('agronomia.fase_fisiologica');

        $this->assertCount(6, $stadi);
        // Le schede compilate prima del passaggio a sei stadi restano valide
        foreach (['giovane', 'adulto', 'maturo', 'senescente'] as $storico) {
            $this->assertContains($storico, $stadi);
        }

        $id = $this->creaAlbero();
        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['age_class' => 'veterano'],
        ])->assertOk();
        $this->getJson("/api/v1/assets/{$id}")
            ->assertOk()->assertJsonPath('data.tree.age_class', 'veterano');
    }

    public function test_il_sito_di_crescita_offre_le_diciassette_voci(): void
    {
        $voci = config('agronomia.sito_di_crescita');

        $this->assertCount(17, $voci);
        $this->assertContains('bauletto rialzato', $voci);
        $this->assertContains('buca nell\'asfalto', $voci);
    }

    public function test_le_tendine_arrivano_alla_pagina_con_le_voci_del_server(): void
    {
        $contenuto = $this->get('/censimento')->assertOk()->getContent();

        // Le prop condivise di Inertia portano i dizionari: la pagina non
        // tiene copie delle voci nel JavaScript
        $this->assertStringContainsString('sito_di_crescita', $contenuto);
        $this->assertStringContainsString('fase_fisiologica', $contenuto);
        $this->assertStringContainsString('bauletto rialzato', $contenuto);
    }

    public function test_lo_storico_racconta_la_modifica_con_l_etichetta_italiana(): void
    {
        $id = $this->creaAlbero();

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['social_position' => 'isolato', 'age_class' => 'veterano'],
        ])->assertOk();

        $storia = $this->getJson("/api/v1/assets/{$id}/versioni")->assertOk()->json('data');
        $modifiche = collect($storia)->flatMap(fn ($r) => $r['modifiche']);

        $posizione = $modifiche->firstWhere('campo', 'Posizione sociale');
        $this->assertNotNull($posizione, 'Lo storico deve raccontare la posizione sociale');
        $this->assertNull($posizione['prima']);
        $this->assertSame('isolato', $posizione['dopo']);

        // La colonna age_class ora si racconta come fase fisiologica
        $fase = $modifiche->firstWhere('campo', 'Fase fisiologica');
        $this->assertNotNull($fase);
        $this->assertSame('veterano', $fase['dopo']);
    }

    public function test_la_scheda_pdf_stampa_i_campi_agronomici(): void
    {
        $stampe = new \Tests\Support\RaccoglitorePdf;
        $this->app->instance(\App\Services\Pdf\PdfRenderer::class, $stampe);

        $id = $this->creaAlbero();
        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => [
                'genus' => 'Quercus', 'species' => 'Quercus robur',
                'crown_insertion_m' => 3.5,
                'age_years_est' => 30, 'age_qualifier' => 'stimata',
                'age_class' => 'maturo', 'vegetative_state' => 'buono',
                'social_position' => 'filare', 'growth_site' => 'banchina stradale erbosa',
                'target' => 'costante',
            ],
        ])->assertOk();

        $this->get("/api/v1/assets/{$id}/pdf")->assertOk();

        $html = $stampe->html['pdf.asset'];
        $this->assertStringContainsString('Dati dendrometrici e agronomici', $html);
        $this->assertStringContainsString('Altezza del primo palco', $html);
        $this->assertStringContainsString('3.50 m', $html);
        $this->assertStringContainsString('30 anni (stimata)', $html);
        $this->assertStringContainsString('Fase fisiologica', $html);
        $this->assertStringContainsString('maturo', $html);
        $this->assertStringContainsString('Stato vegetativo', $html);
        $this->assertStringContainsString('Posizione sociale', $html);
        $this->assertStringContainsString('Sito di crescita', $html);
        $this->assertStringContainsString('banchina stradale erbosa', $html);
        $this->assertStringContainsString('Bersaglio (frequentazione)', $html);
        $this->assertStringContainsString('costante', $html);
    }

    public function test_i_campi_vuoti_non_sporcano_la_scheda_pdf(): void
    {
        $stampe = new \Tests\Support\RaccoglitorePdf;
        $this->app->instance(\App\Services\Pdf\PdfRenderer::class, $stampe);

        $id = $this->creaAlbero();
        $this->get("/api/v1/assets/{$id}/pdf")->assertOk();

        $html = $stampe->html['pdf.asset'];
        // Le classificazioni non compilate non compaiono; l'altezza del primo
        // palco resta come misura non rilevata, alla pari delle altre
        $this->assertStringNotContainsString('Fase fisiologica', $html);
        $this->assertStringNotContainsString('Posizione sociale', $html);
        $this->assertStringNotContainsString('Sito di crescita', $html);
        $this->assertStringNotContainsString('Bersaglio', $html);
        $this->assertStringContainsString('Altezza del primo palco', $html);
    }
}
