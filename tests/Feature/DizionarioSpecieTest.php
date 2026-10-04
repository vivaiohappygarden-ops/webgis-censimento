<?php

namespace Tests\Feature;

use App\Models\TreeSpecies;
use App\Services\Botanica\DizionarioSpecie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Il dizionario delle specie: voci di serie installate dalla migrazione,
 * ricerca a parole su nomi botanici e comuni, specie nuove imparate dalle
 * schede dell'organizzazione e visibili solo a lei, scarico per l'app di campo.
 */
class DizionarioSpecieTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->actingAsTenantUser($this->utente);
    }

    private function albero(): string
    {
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');

        return $this->postJson('/api/v1/assets', ['area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry()])
            ->assertCreated()->json('data.id');
    }

    public function test_le_voci_di_serie_ci_sono_e_la_ricerca_capisce_anche_i_nomi_comuni(): void
    {
        $diSerie = TreeSpecies::query()->whereNull('tenant_id')->count();
        $this->assertGreaterThanOrEqual(200, $diSerie);
        // Rilanciare l'installazione non raddoppia niente
        $this->assertSame(0, DizionarioSpecie::installaDiSerie());
        $this->assertSame($diSerie, TreeSpecies::query()->whereNull('tenant_id')->count());

        $pino = $this->getJson('/api/v1/specie?q=pino+romano')->assertOk()->json('data.0');
        $this->assertSame(['Pinus', 'Pinus pinea', 'Pinaceae', 'Pino domestico', false], [$pino['genus'], $pino['species'], $pino['family'], $pino['common_name'], $pino['propria']]);
        $this->assertSame('Quercus robur', $this->getJson('/api/v1/specie?q=farnia')->json('data.0.species'));
        $this->assertSame('Platanus x acerifolia', $this->getJson('/api/v1/specie?q=PLATANO')->json('data.0.species'));
        $this->assertGreaterThanOrEqual(5, count($this->getJson('/api/v1/specie?q=quercus')->json('data')));
        $this->assertSame([], $this->getJson('/api/v1/specie?q=zzzz')->json('data'));
        // L'elenco intero per il telefono
        $this->assertGreaterThanOrEqual(200, count($this->getJson('/api/v1/specie?tutte=1')->json('data')));
    }

    public function test_una_specie_nuova_salvata_nella_scheda_entra_nel_dizionario_della_sola_organizzazione(): void
    {
        $albero = $this->albero();
        $this->patchJson("/api/v1/assets/{$albero}", ['tree' => ['species' => 'Ficus benjamina', 'genus' => 'Ficus', 'family' => 'Moraceae', 'common_name' => 'Ficus beniamino']])->assertOk();
        $voce = $this->getJson('/api/v1/specie?q=benjamina')->assertOk()->json('data.0');
        $this->assertSame(['Ficus benjamina', 'Moraceae', 'Ficus beniamino', true], [$voce['species'], $voce['family'], $voce['common_name'], $voce['propria']]);
        // Salvare di nuovo la stessa specie, anche scritta diversa, non la raddoppia
        $this->patchJson("/api/v1/assets/{$albero}", ['tree' => ['species' => 'FICUS BENJAMINA']])->assertOk();
        $this->assertSame(1, TreeSpecies::query()->where('tenant_id', $this->organizzazione->id)->count());
        // Una parola sola non e' una specie: non si impara
        $this->patchJson("/api/v1/assets/{$albero}", ['tree' => ['species' => 'Aghifoglia']])->assertOk();
        $this->assertSame(1, TreeSpecies::query()->where('tenant_id', $this->organizzazione->id)->count());
        // Una specie gia' di serie non si copia fra le proprie
        $this->patchJson("/api/v1/assets/{$albero}", ['tree' => ['species' => 'Pinus pinea']])->assertOk();
        $this->assertSame(1, TreeSpecies::query()->where('tenant_id', $this->organizzazione->id)->count());

        // Un'altra organizzazione non la vede, ma vede quelle di serie
        [, $altro] = $this->createTenantUser();
        $this->actingAsTenantUser($altro);
        $this->assertSame([], $this->getJson('/api/v1/specie?q=benjamina')->json('data'));
        $this->assertSame('Pinus pinea', $this->getJson('/api/v1/specie?q=pino+romano')->json('data.0.species'));
    }

    public function test_le_voci_proprie_si_aggiungono_e_si_tolgono_e_quelle_di_serie_restano(): void
    {
        $nuova = $this->postJson('/api/v1/specie', ['species' => 'Prunus x yedoensis', 'family' => 'Rosaceae', 'common_name' => 'Ciliegio di Yoshino', 'synonyms' => ['ciliegio Yoshino']])
            ->assertCreated()->json('data');
        $this->assertSame(['Prunus', true], [$nuova['genus'], $nuova['propria']]);
        $this->assertSame('Prunus x yedoensis', $this->getJson('/api/v1/specie?q=yoshino')->json('data.0.species'));
        // Una specie di serie non si duplica: torna quella
        $risposta = $this->postJson('/api/v1/specie', ['species' => 'cedrus deodara'])->assertOk();
        $this->assertTrue($risposta->json('esistente'));
        $this->assertFalse($risposta->json('data.propria'));

        $this->deleteJson("/api/v1/specie/{$nuova['id']}")->assertNoContent();
        $diSerie = TreeSpecies::query()->whereNull('tenant_id')->first();
        $this->deleteJson("/api/v1/specie/{$diSerie->id}")->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'specie.aggiunta']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'specie.tolta']);

        // Il permesso: il ruolo cliente non cerca ne' aggiunge
        [, $cliente] = $this->createTenantUser([], 'cliente');
        $this->actingAsTenantUser($cliente);
        $this->getJson('/api/v1/specie?q=pino')->assertForbidden();
    }

    public function test_lo_scarico_dell_app_di_campo_porta_il_dizionario(): void
    {
        $specie = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('specie');
        $this->assertGreaterThanOrEqual(200, count($specie));
        $this->assertContains('Pinus pinea', array_column($specie, 'species'));
        $this->assertContains('pino romano', collect($specie)->firstWhere('species', 'Pinus pinea')['synonyms']);
    }
}
