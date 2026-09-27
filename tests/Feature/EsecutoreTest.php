<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Locality;
use App\Models\Photo;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use App\Support\HomeRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Il ruolo "esecutore" (27/09/2026): chi lavora per una ditta esterna
 * rendiconta dal campo i soli lavori affidati alla sua squadra. Riceve sul
 * telefono solo gli elementi di quei lavori, senza note interne; non censisce,
 * non vede gli altri lavori ne' il gestionale; fotografa solo gli elementi
 * dei suoi ordini, indicando l'ordine.
 */
class EsecutoreTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $amministratore;

    private $tipo;

    private Client $comune;

    private $areaComune;

    private $areaAltrui;

    private User $esecutore;

    private Team $ditta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organizzazione, $this->amministratore] = $this->createTenantUser();
        $this->tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');

        $this->comune = Client::create(['tenant_id' => $this->organizzazione->id, 'name' => 'Comune di Prova', 'client_type' => 'public']);
        $sede = Site::create(['tenant_id' => $this->organizzazione->id, 'client_id' => $this->comune->id, 'name' => 'Sede']);
        $localita = Locality::create(['tenant_id' => $this->organizzazione->id, 'site_id' => $sede->id, 'name' => 'Centro']);
        $this->areaComune = $this->createArea($this->organizzazione, ['locality_id' => $localita->id, 'name' => 'Parco del Comune']);
        // Un altro committente, che la ditta non deve mai vedere
        $this->areaAltrui = $this->createArea($this->organizzazione, ['name' => 'Giardino Altrui']);

        // La ditta esterna del Comune e il suo esecutore
        $this->ditta = Team::create(['tenant_id' => $this->organizzazione->id, 'name' => 'Verde Srl', 'is_external' => true, 'client_id' => $this->comune->id]);
        $this->esecutore = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $this->esecutore->assignRole('esecutore');
        DB::table('team_members')->insert(['tenant_id' => $this->organizzazione->id, 'team_id' => $this->ditta->id, 'user_id' => $this->esecutore->id]);
    }

    private function albero($area, string $cartellino, string $note = 'nota interna'): string
    {
        $this->actingAsTenantUser($this->amministratore);
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id, 'object_type_id' => $this->tipo->id, 'census_code' => $cartellino,
            'geometry' => $this->pointGeometry(), 'notes' => $note,
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$id}", ['tree' => ['species' => 'Tilia cordata']])->assertOk();

        return $id;
    }

    private function ordine(array $attributi, array $elementi = []): WorkOrder
    {
        $ordine = WorkOrder::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => WorkOrder::nextCode($this->organizzazione->id),
            'status' => 'assigned',
            'created_by' => $this->amministratore->id,
            'updated_by' => $this->amministratore->id,
            ...$attributi,
        ]);
        foreach ($elementi as $assetId) {
            WorkOrderAsset::create(['tenant_id' => $this->organizzazione->id, 'work_order_id' => $ordine->id, 'asset_id' => $assetId]);
        }

        return $ordine;
    }

    public function test_il_ruolo_esiste_con_il_solo_permesso_di_esecuzione_e_atterra_in_campo(): void
    {
        $this->assertTrue($this->esecutore->can('works.execute'));
        foreach (['assets.view', 'assets.create', 'assets.update', 'works.view', 'works.manage'] as $p) {
            $this->assertFalse($this->esecutore->can($p), "l'esecutore non deve avere {$p}");
        }
        $this->assertSame('operatore', HomeRoute::for($this->esecutore));
        // L'amministratore ha anche il permesso nuovo
        $this->assertTrue($this->amministratore->can('works.execute'));
    }

    public function test_apre_l_app_di_campo_ma_non_il_gestionale(): void
    {
        $this->actingAsTenantUser($this->esecutore);
        $this->get('/operatore')->assertOk();
        $this->get('/patrimonio')->assertForbidden();
        $this->get('/lavori')->assertForbidden();
        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->getJson('/api/v1/work-orders')->assertForbidden();
        $this->getJson('/api/v1/sync/bootstrap')->assertOk();
    }

    public function test_sul_telefono_arrivano_solo_i_lavori_affidati_con_i_loro_elementi_senza_note(): void
    {
        $mio = $this->albero($this->areaComune, 'COM-0001', 'appunto riservato del tecnico');
        $altroDelComune = $this->albero($this->areaComune, 'COM-0002');
        $altrui = $this->albero($this->areaAltrui, 'ALT-0001');

        $affidato = $this->ordine(['title' => 'Potatura tiglio', 'team_id' => $this->ditta->id, 'area_id' => $this->areaComune->id, 'client_id' => $this->comune->id], [$mio]);
        $this->ordine(['title' => 'Lavoro di altri', 'area_id' => $this->areaAltrui->id], [$altrui]);
        $this->ordine(['title' => 'Lavoro del Comune ad altra squadra', 'area_id' => $this->areaComune->id, 'client_id' => $this->comune->id], [$altroDelComune]);

        $this->actingAsTenantUser($this->esecutore);
        $scarico = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json();

        $this->assertSame(['Potatura tiglio'], array_column($scarico['work_orders'], 'title'));
        $this->assertSame(['COM-0001'], array_column($scarico['assets'], 'census_code'));
        $this->assertArrayNotHasKey('notes', $scarico['assets'][0]);
        $this->assertSame(['Parco del Comune'], array_column($scarico['areas'], 'name'));
        $this->assertSame([], $scarico['inspection_templates']);
        $this->assertSame([], $scarico['clients']);
        $this->assertStringNotContainsString('appunto riservato', json_encode($scarico));
        $this->assertStringNotContainsString('Altrui', json_encode($scarico));

        // Il delta rimanda il perimetro e toglie cio' che ne e' uscito
        $cambiamenti = $this->getJson('/api/v1/sync/changes?cursor=0&ids[]='.$altrui)->assertOk()->json('changes');
        $upsert = collect($cambiamenti)->where('op', 'upsert');
        $this->assertContains('COM-0001', $upsert->where('table', 'assets')->pluck('row.census_code')->all());
        $this->assertNotContains('ALT-0001', $upsert->where('table', 'assets')->pluck('row.census_code')->all());
        $this->assertContains($altrui, collect($cambiamenti)->where('op', 'delete')->where('table', 'assets')->pluck('id')->all());
        $this->assertSame([$affidato->id], $upsert->where('table', 'work_orders')->pluck('row.id')->values()->all());
    }

    public function test_rendiconta_il_suo_lavoro_ma_non_censisce_e_non_tocca_i_lavori_altrui(): void
    {
        $mio = $this->albero($this->areaComune, 'COM-0001');
        $altrui = $this->albero($this->areaAltrui, 'ALT-0001');
        $affidato = $this->ordine(['title' => 'Potatura tiglio', 'team_id' => $this->ditta->id, 'area_id' => $this->areaComune->id], [$mio]);
        $nonMio = $this->ordine(['title' => 'Lavoro di altri', 'area_id' => $this->areaAltrui->id], [$altrui]);

        $this->actingAsTenantUser($this->esecutore);
        $seq = 0;
        $comando = function (string $tipo, array $payload) use (&$seq) {
            return [
                'idempotency_key' => (string) Str::uuid(), 'device_seq' => ++$seq, 'type' => $tipo,
                'entity_id' => (string) Str::uuid7(), 'payload' => $payload, 'client_ts' => now()->toIso8601String(),
                ...($tipo === 'asset.create' ? ['geom' => $this->pointGeometry()] : []),
            ];
        };
        $esiti = $this->postJson('/api/v1/sync/batch', ['batch_id' => (string) Str::uuid(), 'device_id' => 'dev-ditta-0001', 'schema' => 1, 'commands' => [
            $comando('work_log.add', ['work_order_id' => $affidato->id, 'asset_id' => $mio, 'started_at' => now()->subHour()->toIso8601String(), 'ended_at' => now()->toIso8601String(), 'quantity' => 1, 'unit' => 'cad']),
            $comando('work_log.add', ['work_order_id' => $nonMio->id, 'started_at' => now()->subHour()->toIso8601String()]),
            $comando('asset.create', ['area_id' => $this->areaComune->id, 'object_type_id' => $this->tipo->id]),
            $comando('inspection.complete', ['template_id' => (string) Str::uuid7()]),
        ]])->assertOk()->json('results');

        $this->assertSame('applied', $esiti[0]['status']);
        $this->assertSame('rejected', $esiti[1]['status']);
        $this->assertSame('FORBIDDEN', $esiti[1]['error_code'] ?? $esiti[1]['code'] ?? null);
        $this->assertSame('rejected', $esiti[2]['status']);
        $this->assertSame('rejected', $esiti[3]['status']);
        $this->assertSame(0, Asset::query()->where('area_id', $this->areaComune->id)->where('census_code', null)->count());
    }

    public function test_fotografa_solo_gli_elementi_dei_suoi_lavori_indicando_il_lavoro(): void
    {
        $mio = $this->albero($this->areaComune, 'COM-0001');
        $altrui = $this->albero($this->areaAltrui, 'ALT-0001');
        $affidato = $this->ordine(['title' => 'Potatura tiglio', 'team_id' => $this->ditta->id, 'area_id' => $this->areaComune->id], [$mio]);
        $nonMio = $this->ordine(['title' => 'Lavoro di altri', 'area_id' => $this->areaAltrui->id], [$altrui]);

        $this->actingAsTenantUser($this->esecutore);
        $foto = fn () => UploadedFile::fake()->image('dopo.jpg', 640, 480);
        $id = $this->postJson("/api/v1/assets/{$mio}/photos", ['photo' => $foto(), 'work_order_id' => $affidato->id, 'category' => 'after'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/assets/{$mio}/photos", ['photo' => $foto()])->assertForbidden();
        $this->postJson("/api/v1/assets/{$altrui}/photos", ['photo' => $foto(), 'work_order_id' => $nonMio->id])->assertForbidden();
        $this->postJson("/api/v1/assets/{$altrui}/photos", ['photo' => $foto(), 'work_order_id' => $affidato->id])->assertForbidden();

        // Rivede la foto del suo elemento, non quelle degli altri
        $this->get("/api/v1/photos/{$id}/file")->assertOk();
        $this->actingAsTenantUser($this->amministratore);
        $fotoAltrui = $this->postJson("/api/v1/assets/{$altrui}/photos", ['photo' => $foto()])->assertCreated()->json('data.id');
        $this->actingAsTenantUser($this->esecutore);
        $this->get("/api/v1/photos/{$fotoAltrui}/file")->assertForbidden();
        $this->assertSame($affidato->id, Photo::query()->find($id)->subject_id);
    }

    public function test_l_amministratore_lo_crea_dalla_pagina_utenti_come_gli_altri_ruoli(): void
    {
        $this->actingAsTenantUser($this->amministratore);
        $creato = $this->postJson('/api/v1/users', ['name' => 'Operaio Verde', 'email' => 'operaio@verde.test', 'role' => 'esecutore'])
            ->assertCreated()->json('data');
        $this->assertSame('esecutore', $creato['role']);
        $this->assertContains('works.execute', array_column($this->getJson('/api/v1/roles')->assertOk()->json('permessi') ?? [], 'chiave'));
    }
}
