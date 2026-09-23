<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Locality;
use App\Models\Site;
use App\Support\PortalLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Dal campo si apre un'area di lavoro nuova, anche per un committente nuovo,
 * e il rilievo di un albero porta con se' specie e misure (decisione
 * committente 23/09/2026). Qui si collaudano le regole del comando di
 * sincronizzazione `area.create` e del blocco `tree` di `asset.create`.
 */
class CampoAreeTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private Area $area;

    private $treeType;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser(role: 'amministratore');
        $this->area = $this->createArea($this->organization);
        $this->treeType = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function batch(array $commands): array
    {
        return [
            'batch_id' => (string) Str::uuid(),
            'device_id' => 'dev-test-0001',
            'schema' => 1,
            'commands' => $commands,
        ];
    }

    private function comandoArea(array $payload, ?array $geom = null, array $overrides = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 1,
            'type' => 'area.create',
            'entity_id' => (string) Str::uuid(),
            'payload' => ['name' => 'Giardino di prova', ...$payload],
            'geom' => $geom ?? $this->squarePolygon(),
            'client_ts' => now()->toIso8601String(),
            ...$overrides,
        ];
    }

    private function comandoElemento(string $areaId, array $payload = [], array $overrides = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 2,
            'type' => 'asset.create',
            'entity_id' => (string) Str::uuid(),
            'payload' => ['area_id' => $areaId, 'object_type_id' => $this->treeType->id, ...$payload],
            'geom' => ['type' => 'Point', 'coordinates' => [9.1905, 45.4652]],
            'client_ts' => now()->toIso8601String(),
            ...$overrides,
        ];
    }

    private function committenteDiProva(): Client
    {
        $cliente = PortalLabels::clientOfArea($this->area->id);
        $cliente->forceFill(['label_prefix' => 'CLT'])->save();

        return $cliente;
    }

    public function test_un_area_nata_in_campo_sta_sotto_il_suo_committente_e_numera_gli_elementi(): void
    {
        $cliente = $this->committenteDiProva();
        $areaCmd = $this->comandoArea(['client_id' => $cliente->id, 'name' => 'Giardino via Roma 12']);
        $elementoCmd = $this->comandoElemento($areaCmd['entity_id']);

        // Area ed elemento nello stesso invio: l'ordine FIFO basta
        $risposta = $this->postJson('/api/v1/sync/batch', $this->batch([$areaCmd, $elementoCmd]))->assertOk();
        $risposta->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.entity_id', $areaCmd['entity_id'])
            ->assertJsonPath('results.1.status', 'applied');

        $area = Area::query()->findOrFail($areaCmd['entity_id']);
        $this->assertSame('Giardino via Roma 12', $area->name);
        $this->assertSame('active', $area->status);
        $this->assertGreaterThan(0, (float) $area->computed_area_sqm);

        // Localita' nuova con il nome dell'area, sotto la sede che il committente ha gia'
        $localita = Locality::query()->findOrFail($area->locality_id);
        $this->assertSame('Giardino via Roma 12', $localita->name);
        $this->assertSame('Sede Test', Site::query()->findOrFail($localita->site_id)->name);
        $this->assertSame(1, Site::query()->where('client_id', $cliente->id)->count());

        // Il codice del censimento arriva dal prefisso del committente
        $this->assertSame('CLT-0001', Asset::query()->findOrFail($elementoCmd['entity_id'])->census_code);

        // E l'area torna sui telefoni con il nome del committente
        $aree = collect($this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('areas'));
        $this->assertSame('Cliente Test', $aree->firstWhere('id', $area->id)['client_name']);
    }

    public function test_un_committente_nuovo_nasce_dal_campo_con_prefisso_sede_e_localita(): void
    {
        $idCliente = (string) Str::uuid();
        $nuovo = ['id' => $idCliente, 'name' => 'Condominio Le Querce', 'client_type' => 'condo'];
        $prima = $this->comandoArea(['client' => $nuovo, 'name' => 'Giardino condominiale']);
        $seconda = $this->comandoArea(['client' => $nuovo, 'name' => 'Parcheggio alberato'], overrides: ['device_seq' => 2]);
        $elemento = $this->comandoElemento($prima['entity_id'], overrides: ['device_seq' => 3]);

        $this->postJson('/api/v1/sync/batch', $this->batch([$prima, $seconda, $elemento]))->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied')
            ->assertJsonPath('results.2.status', 'applied');

        $cliente = Client::query()->findOrFail($idCliente);
        $this->assertSame('Condominio Le Querce', $cliente->name);
        $this->assertSame('condo', $cliente->client_type);
        $this->assertNotEmpty($cliente->label_prefix);
        $this->assertStringContainsString('dal campo', $cliente->notes);

        // Un solo committente e una sola sede anche con due aree nello stesso giro
        $this->assertSame(1, Client::query()->where('name', 'Condominio Le Querce')->count());
        $sedi = Site::query()->where('client_id', $idCliente)->get();
        $this->assertCount(1, $sedi);
        $this->assertSame('Condominio Le Querce', $sedi[0]->name);
        $this->assertSame(2, Locality::query()->where('site_id', $sedi[0]->id)->count());

        $this->assertSame($cliente->label_prefix.'-0001', Asset::query()->findOrFail($elemento['entity_id'])->census_code);
    }

    public function test_senza_il_permesso_sulle_aree_o_sui_committenti_il_comando_e_respinto(): void
    {
        // L'operatore di serie non apre aree
        [$org, $operatore] = $this->createTenantUser(role: 'operatore');
        $areaOperatore = $this->createArea($org);
        $this->actingAsTenantUser($operatore);
        $cliente = PortalLabels::clientOfArea($areaOperatore->id);
        $this->postJson('/api/v1/sync/batch', $this->batch([$this->comandoArea(['client_id' => $cliente->id])]))
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'FORBIDDEN');

        // Il tecnico apre aree per i committenti che ci sono, ma non ne crea di nuovi
        [$org2, $tecnico] = $this->createTenantUser(role: 'tecnico');
        $areaTecnico = $this->createArea($org2);
        $this->actingAsTenantUser($tecnico);
        $clienteTecnico = PortalLabels::clientOfArea($areaTecnico->id);
        $idNuovo = (string) Str::uuid();

        $this->postJson('/api/v1/sync/batch', $this->batch([
            $this->comandoArea(['client' => ['id' => $idNuovo, 'name' => 'Villa Nuova']]),
        ]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'FORBIDDEN');
        $this->assertDatabaseMissing('clients', ['id' => $idNuovo]);

        $this->postJson('/api/v1/sync/batch', $this->batch([
            $this->comandoArea(['client_id' => $clienteTecnico->id]),
        ]))->assertOk()->assertJsonPath('results.0.status', 'applied');
    }

    public function test_il_perimetro_deve_essere_un_poligono_valido_e_lo_stato_uno_ammesso(): void
    {
        $cliente = $this->committenteDiProva();

        // Un punto non e' un perimetro
        $this->postJson('/api/v1/sync/batch', $this->batch([
            $this->comandoArea(['client_id' => $cliente->id], ['type' => 'Point', 'coordinates' => [9.19, 45.46]]),
        ]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'VALIDATION_FAILED');

        // Un contorno a clessidra non e' una superficie
        $clessidra = ['type' => 'Polygon', 'coordinates' => [[
            [9.190, 45.464], [9.193, 45.466], [9.193, 45.464], [9.190, 45.466], [9.190, 45.464],
        ]]];
        $this->postJson('/api/v1/sync/batch', $this->batch([
            $this->comandoArea(['client_id' => $cliente->id], $clessidra),
        ]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'VALIDATION_FAILED');

        // Dal campo un'area nasce attiva o prevista, non dismessa
        $this->postJson('/api/v1/sync/batch', $this->batch([
            $this->comandoArea(['client_id' => $cliente->id, 'status' => 'dismissed']),
        ]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'VALIDATION_FAILED');

        // Senza dire di chi e' l'area, niente
        $this->postJson('/api/v1/sync/batch', $this->batch([$this->comandoArea([])]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'VALIDATION_FAILED');

        // Il perimetro provvisorio entra come "prevista"
        $prevista = $this->comandoArea(['client_id' => $cliente->id, 'status' => 'planned', 'notes' => 'Perimetro provvisorio']);
        $this->postJson('/api/v1/sync/batch', $this->batch([$prevista]))->assertOk()
            ->assertJsonPath('results.0.status', 'applied');
        $this->assertSame('planned', Area::query()->findOrFail($prevista['entity_id'])->status);

        $this->assertSame(1, Area::query()->where('name', 'Giardino di prova')->count());
    }

    public function test_lo_stesso_identificativo_non_crea_due_aree(): void
    {
        $cliente = $this->committenteDiProva();
        $comando = $this->comandoArea(['client_id' => $cliente->id]);
        $batch = $this->batch([$comando]);

        $this->postJson('/api/v1/sync/batch', $batch)->assertOk()->assertJsonPath('results.0.status', 'applied');
        // Stesso invio ripetuto dopo un timeout: esito originale, nessuna seconda area
        $this->postJson('/api/v1/sync/batch', $batch)->assertOk()->assertJsonPath('results.0.status', 'duplicate');
        // Chiave nuova sullo stesso identificativo: collisione dichiarata
        $this->postJson('/api/v1/sync/batch', $this->batch([
            [...$comando, 'idempotency_key' => (string) Str::uuid()],
        ]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'ID_COLLISION');

        $this->assertSame(1, Area::query()->where('name', 'Giardino di prova')->count());
        $this->assertSame(1, Locality::query()->where('name', 'Giardino di prova')->count());
    }

    public function test_specie_e_misure_entrano_con_il_rilievo_senza_una_revisione_in_piu(): void
    {
        $comando = $this->comandoElemento($this->area->id, ['tree' => [
            'species' => 'Tilia cordata', 'common_name' => 'Tiglio selvatico', 'height_m' => 12.5,
            'dbh_cm' => 38, 'crown_diameter_m' => 7, 'vegetative_state' => 'buono', 'trunk_count' => null,
        ]]);

        $this->postJson('/api/v1/sync/batch', $this->batch([$comando]))->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.version', 1);

        $asset = Asset::query()->with('tree')->findOrFail($comando['entity_id']);
        $this->assertSame('Tilia cordata', $asset->tree->species);
        $this->assertSame('Tiglio selvatico', $asset->tree->common_name);
        $this->assertEquals(12.5, (float) $asset->tree->height_m);
        $this->assertEquals(38, (float) $asset->tree->dbh_cm);
        $this->assertSame('buono', $asset->tree->vegetative_state);
        $this->assertSame(1, (int) $asset->tree->trunk_count);
        $this->assertSame(1, $asset->version);
        $this->assertSame(0, (int) DB::table('asset_versions')->where('asset_id', $asset->id)->count());

        // Uno stato vegetativo fuori dizionario non entra
        $this->postJson('/api/v1/sync/batch', $this->batch([
            $this->comandoElemento($this->area->id, ['tree' => ['vegetative_state' => 'ottimo']]),
        ]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'VALIDATION_FAILED');

        // E su un tipo senza scheda albero specie e misure non hanno posto
        $panchina = $this->makeObjectType($this->organization, 'P');
        $this->postJson('/api/v1/sync/batch', $this->batch([
            $this->comandoElemento($this->area->id, ['object_type_id' => $panchina->id, 'tree' => ['species' => 'Tilia']]),
        ]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'VALIDATION_FAILED');
    }

    public function test_lo_scarico_dei_dati_porta_i_committenti_solo_a_chi_puo_aprire_aree(): void
    {
        $clienti = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('clients');
        $this->assertSame(['Cliente Test'], array_column($clienti, 'name'));

        [$org, $operatore] = $this->createTenantUser(role: 'operatore');
        $this->createArea($org);
        $this->actingAsTenantUser($operatore);
        $this->assertSame([], $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('clients'));
    }

    public function test_il_pull_riprende_per_id_un_area_rimasta_indietro(): void
    {
        $cliente = $this->committenteDiProva();
        $comando = $this->comandoArea(['client_id' => $cliente->id]);
        $this->postJson('/api/v1/sync/batch', $this->batch([$comando]))->assertOk();

        $cursore = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('cursor');

        // Oltre il cursore non c'e' niente di nuovo: l'area arriva perche' richiesta per id
        $cambiamenti = $this->getJson('/api/v1/sync/changes?cursor='.$cursore.'&ids[]='.$comando['entity_id'])
            ->assertOk()->json('changes');
        $this->assertCount(1, $cambiamenti);
        $this->assertSame('areas', $cambiamenti[0]['table']);
        $this->assertSame('upsert', $cambiamenti[0]['op']);
        $this->assertSame('Cliente Test', $cambiamenti[0]['row']['client_name']);
    }
}
