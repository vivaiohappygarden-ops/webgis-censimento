<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Tree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Specie e misure su piu' alberi in una volta.
 *
 * E' l'azione piu' pericolosa del programma: qui si controlla che le sue
 * difese tengano. Si scrivono solo i campi scelti; "solo i vuoti" riempie i
 * buchi senza cancellare niente; l'anteprima conta le schede che
 * cambierebbero davvero; lo storico registra i valori vecchi, non i nuovi.
 */
class ModificaAlberiMultiplaTest extends TestCase
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

    private function creaAlbero(array $scheda = []): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        if ($scheda !== []) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $scheda])->assertOk();
        }

        return $id;
    }

    public function test_si_cambiano_specie_e_misure_su_piu_alberi(): void
    {
        $primo = $this->creaAlbero(['genus' => 'Acer']);
        $secondo = $this->creaAlbero(['genus' => 'Acer']);

        $risposta = $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$primo, $secondo],
            'campi' => ['species' => 'Tilia cordata', 'genus' => 'Tilia', 'dbh_cm' => 42.5],
        ])->assertOk();

        $risposta->assertJsonCount(2, 'data.modificati')->assertJsonCount(0, 'data.saltati');

        foreach ([$primo, $secondo] as $id) {
            $albero = Tree::query()->findOrFail($id);
            $this->assertSame('Tilia cordata', $albero->species);
            $this->assertSame('Tilia', $albero->genus);
            $this->assertSame('42.5', (string) $albero->dbh_cm);
        }
    }

    public function test_si_toccano_solo_i_campi_indicati(): void
    {
        $id = $this->creaAlbero(['genus' => 'Acer', 'species' => 'Acer campestre', 'height_m' => 9]);

        $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$id], 'campi' => ['dbh_cm' => 30],
        ])->assertOk();

        $albero = Tree::query()->findOrFail($id);
        $this->assertSame('Acer campestre', $albero->species, 'La specie non era fra i campi scelti');
        $this->assertSame('9.00', (string) $albero->height_m);
    }

    public function test_solo_i_vuoti_non_sovrascrive_quello_che_c_e(): void
    {
        $conSpecie = $this->creaAlbero(['species' => 'Quercus ilex']);
        $senzaSpecie = $this->creaAlbero(['genus' => 'Quercus']);

        $risposta = $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$conSpecie, $senzaSpecie],
            'campi' => ['species' => 'Quercus robur'],
            'solo_vuoti' => 1,
        ])->assertOk();

        $this->assertSame('Quercus ilex', Tree::query()->findOrFail($conSpecie)->species);
        $this->assertSame('Quercus robur', Tree::query()->findOrFail($senzaSpecie)->species);

        $risposta->assertJsonCount(1, 'data.modificati')->assertJsonCount(1, 'data.saltati');
        $this->assertStringContainsString("c'e' gia' un valore", $risposta->json('data.saltati.0.motivo'));
    }

    public function test_un_valore_gia_uguale_non_e_una_modifica(): void
    {
        $id = $this->creaAlbero(['species' => 'Tilia cordata', 'dbh_cm' => 38]);

        $risposta = $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$id], 'campi' => ['species' => 'Tilia cordata', 'dbh_cm' => 38],
        ])->assertOk();

        $risposta->assertJsonCount(0, 'data.modificati')->assertJsonCount(1, 'data.saltati');
        $this->assertStringContainsString("valore gia' uguale", $risposta->json('data.saltati.0.motivo'));
    }

    public function test_l_anteprima_conta_quello_che_la_conferma_cambia(): void
    {
        $daCambiare = $this->creaAlbero(['species' => 'Acer campestre']);
        $giaUguale = $this->creaAlbero(['species' => 'Tilia cordata']);

        $prova = $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$daCambiare, $giaUguale],
            'campi' => ['species' => 'Tilia cordata'],
            'prova' => 1,
        ])->assertOk();

        $prova->assertJsonCount(1, 'data.modificati');
        // La prova non scrive
        $this->assertSame('Acer campestre', Tree::query()->findOrFail($daCambiare)->species);

        $conferma = $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$daCambiare, $giaUguale],
            'campi' => ['species' => 'Tilia cordata'],
        ])->assertOk();

        $this->assertCount(
            count($prova->json('data.modificati')),
            $conferma->json('data.modificati'),
            'Anteprima ed esito passano dallo stesso metodo: devono contare uguale',
        );
    }

    public function test_lo_storico_registra_i_valori_vecchi(): void
    {
        $id = $this->creaAlbero(['species' => 'Acer campestre', 'dbh_cm' => 20]);
        $versioniPrima = AssetVersion::query()->where('asset_id', $id)->count();

        $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$id], 'campi' => ['species' => 'Tilia cordata', 'dbh_cm' => 41],
        ])->assertOk();

        // La versione della scheda avanza anche se cambia solo l'albero
        $this->assertSame(1, AssetVersion::query()->where('asset_id', $id)->count() - $versioniPrima);

        $ultima = AssetVersion::query()->where('asset_id', $id)->orderByDesc('version')->firstOrFail();
        $albero = $ultima->snapshot['albero'];
        $this->assertSame('Acer campestre', $albero['species'], 'La fotografia riprende i valori vecchi');
        // La fotografia tiene il valore grezzo del database, non quello
        // formattato dal modello: si confronta come numero
        $this->assertEquals(20, $albero['dbh_cm']);

        // E l'autore della modifica finisce sulla scheda
        $this->assertSame($this->utente->id, Asset::query()->findOrFail($id)->updated_by);
    }

    public function test_le_schede_in_archivio_e_i_non_alberi_restano_fuori(): void
    {
        $inArchivio = $this->creaAlbero(['species' => 'Acer campestre']);
        DB::table('assets')->where('id', $inArchivio)->update(['status' => 'removed']);

        $tipoPanchina = $this->makeObjectType($this->organizzazione, 'P', 'P214001');
        $panchina = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $tipoPanchina->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $risposta = $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$inArchivio, $panchina],
            'campi' => ['species' => 'Tilia cordata'],
        ])->assertOk();

        $risposta->assertJsonCount(0, 'data.modificati')->assertJsonCount(2, 'data.saltati');
        $motivi = collect($risposta->json('data.saltati'))->pluck('motivo', 'id');
        $this->assertStringContainsString('In archivio', $motivi[$inArchivio]);
        $this->assertStringContainsString("Non e' una scheda albero", $motivi[$panchina]);
    }

    public function test_un_campo_non_modificabile_in_blocco_viene_rifiutato(): void
    {
        $id = $this->creaAlbero();

        // Monumentale, dedicato e date sono decisioni una per una: si rifiuta
        // chiaramente invece di ignorare la chiave in silenzio
        $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$id], 'campi' => ['is_monumental' => true],
        ])->assertStatus(422);

        $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$id], 'campi' => [],
        ])->assertStatus(422);
    }

    public function test_un_valore_fuori_scala_non_passa(): void
    {
        $id = $this->creaAlbero();

        $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$id], 'campi' => ['dbh_cm' => 99999],
        ])->assertStatus(422);

        $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [$id], 'campi' => ['vegetative_state' => 'inventato'],
        ])->assertStatus(422);
    }

    public function test_senza_il_permesso_di_modifica_non_si_tocca_niente(): void
    {
        [$organizzazione, $cliente] = $this->createTenantUser([], 'cliente');
        $this->actingAsTenantUser($cliente);

        $this->postJson('/api/v1/azioni/alberi', [
            'ids' => [(string) \Illuminate\Support\Str::uuid()],
            'campi' => ['species' => 'Tilia cordata'],
        ])->assertForbidden();
    }
}
