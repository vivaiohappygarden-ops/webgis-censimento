<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * La pagina Oggi della veste nuova: un'unica lista in ordine di urgenza con
 * i numeri veri di ogni sezione (gli stessi del cruscotto precedente, perche'
 * la definizione e' una sola), quello che e' arrivato dal campo, i documenti
 * da chiudere e i portali pubblici. Ogni sezione segue i permessi.
 */
class OggiNuovoTest extends TestCase
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

    private function ordine(array $attributi = []): WorkOrder
    {
        return WorkOrder::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => WorkOrder::nextCode($this->organizzazione->id),
            'title' => 'Lavoro di prova',
            'status' => 'planned',
            'area_id' => $this->area->id,
            'created_by' => $this->utente->id,
            'updated_by' => $this->utente->id,
            ...$attributi,
        ]);
    }

    private function albero(): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    private function valuta(string $assetId, string $scadenza): array
    {
        return $this->postJson("/api/v1/assets/{$assetId}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => '2026-01-15',
            'failure_class' => 'C',
            'next_check_due' => $scadenza,
        ])->assertCreated()->json('data');
    }

    public function test_la_lista_e_in_ordine_di_urgenza_e_i_numeri_tornano_con_il_cruscotto(): void
    {
        $vecchio = $this->ordine(['title' => 'Potatura ferma da dieci giorni',
            'planned_start' => now()->subDays(15)->toDateString(), 'planned_end' => now()->subDays(10)->toDateString()]);
        $recente = $this->ordine(['title' => 'Sfalcio scaduto ieri',
            'planned_start' => now()->subDays(3)->toDateString(), 'planned_end' => now()->subDay()->toDateString()]);
        $this->ordine(['title' => 'Diserbo della settimana prossima',
            'planned_start' => now()->addDays(2)->toDateString(), 'planned_end' => now()->addDays(3)->toDateString()]);
        $this->ordine(['title' => 'Chiuso bene', 'status' => 'completed', 'planned_end' => now()->subDays(3)->toDateString()]);

        $albero = $this->albero();
        $this->valuta($albero, now('Europe/Rome')->subDays(5)->toDateString());

        $oggi = $this->getJson('/api/v1/oggi')->assertOk()->json('data');
        $cruscotto = $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data');

        // I numeri sono gli stessi del cruscotto precedente
        $this->assertSame($cruscotto['work_orders']['overdue_count'], $oggi['conteggi']['lavori_ritardo']);
        $this->assertSame($cruscotto['work_orders']['week_count'], $oggi['conteggi']['lavori_settimana']);
        $this->assertSame($cruscotto['vta']['overdue_count'], $oggi['conteggi']['vta_scaduti']);
        $this->assertSame(2, $oggi['conteggi']['lavori_ritardo']);
        $this->assertSame(1, $oggi['conteggi']['vta_scaduti']);
        $this->assertSame(4, $oggi['conteggi']['totale']);
        $this->assertSame(['lavori' => 3, 'controlli' => 1, 'segnalazioni' => 0, 'altro' => 0], $oggi['conteggi']['famiglie']);

        // Prima i ritardi, dal piu' vecchio (dieci giorni, poi il ricontrollo
        // VTA di cinque, poi ieri), in fondo quello in programma
        $urgenze = array_column($oggi['voci'], 'urgenza');
        $this->assertSame(['ritardo', 'ritardo', 'ritardo', 'programma'], $urgenze);
        $this->assertStringContainsString($vecchio->code, $oggi['voci'][0]['titolo']);
        $this->assertSame('vta', $oggi['voci'][1]['tipo']);
        $this->assertStringContainsString($recente->code, $oggi['voci'][2]['titolo']);
        $this->assertSame('/lavori?ordine='.$vecchio->code, $oggi['voci'][0]['azioni'][0]['href']);
        $this->assertSame(['Valuta', 'Scheda'], array_column($oggi['voci'][1]['azioni'], 'label'));
        $this->assertSame("/censimento/{$albero}?vta=1", $oggi['voci'][1]['azioni'][0]['href']);
        $this->assertStringContainsString('doveva chiudersi il', $oggi['voci'][0]['dettaglio']);
        $this->assertStringContainsString('senza ordine', $oggi['voci'][1]['dettaglio']);
        $this->assertStringStartsWith('Albero senza cartellino', $oggi['voci'][1]['titolo']);
        $this->assertNotEmpty($oggi['giorno']);
    }

    public function test_campo_documenti_e_portali(): void
    {
        $albero = $this->albero();
        $valutazione = $this->valuta($albero, now('Europe/Rome')->addYear()->toDateString());
        $nascosto = $this->albero();
        DB::table('assets')->where('id', $nascosto)->update(['public_hidden' => true]);

        // Arrivato dal campo oggi: un rilievo e una misura, dallo stesso operatore
        foreach (['asset.create', 'asset.update_measures'] as $comando) {
            DB::table('sync_operations')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->organizzazione->id,
                'idempotency_key' => (string) Str::uuid(), 'batch_id' => (string) Str::uuid(),
                'device_id' => 'telefono-1', 'user_id' => $this->utente->id,
                'command_type' => $comando, 'entity_id' => $albero, 'status' => 'applied',
                'result' => '{}', 'created_at' => now()->toIso8601String(),
            ]);
        }
        // Ieri sera non conta
        DB::table('sync_operations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->organizzazione->id,
            'idempotency_key' => (string) Str::uuid(), 'batch_id' => (string) Str::uuid(),
            'device_id' => 'telefono-1', 'user_id' => $this->utente->id,
            'command_type' => 'asset.create', 'entity_id' => $albero, 'status' => 'applied',
            'result' => '{}', 'created_at' => now('Europe/Rome')->startOfDay()->subMinute()->toIso8601String(),
        ]);

        // Una perizia emessa e non ancora validata
        DB::table('tree_assessments')->where('id', $valutazione['id'])
            ->update(['report_number' => 'P-2026-007', 'report_issued_at' => now()]);

        // Il portale del committente e' acceso, senza recapiti
        $cliente = Client::withoutGlobalScopes()->where('tenant_id', $this->organizzazione->id)->firstOrFail();
        $cliente->forceFill(['public_enabled' => true, 'public_slug' => 'comune-prova', 'public_profile' => ['contact_email' => 'verde@comune.it']])->save();

        $oggi = $this->getJson('/api/v1/oggi')->assertOk()->json('data');

        $this->assertSame(1, $oggi['campo']['rilievi']);
        $this->assertSame(1, $oggi['campo']['misure']);
        $this->assertSame(0, $oggi['campo']['aree']);
        $this->assertSame(1, $oggi['campo']['operatori']);
        $this->assertCount(2, $oggi['campo']['righe']);
        $this->assertSame($this->utente->name, $oggi['campo']['righe'][0]['utente']);

        $this->assertSame(1, $oggi['documenti']['perizie_da_validare']);
        $this->assertSame('P-2026-007', $oggi['documenti']['righe'][0]['numero']);
        $this->assertSame($albero, $oggi['documenti']['righe'][0]['asset_id']);

        $this->assertCount(1, $oggi['portali']);
        $this->assertSame('comune-prova', $oggi['portali'][0]['slug']);
        $this->assertSame(1, $oggi['portali'][0]['pubblicati']);
        $this->assertSame(1, $oggi['portali'][0]['nascosti']);
        $this->assertSame(3, $oggi['portali'][0]['recapiti_mancanti']);
        $this->assertFalse($oggi['portali'][0]['copertina']);

        // Validata, la perizia esce dai documenti da chiudere
        $this->postJson("/api/v1/assessments/{$valutazione['id']}/valida")->assertOk();
        $this->assertSame(0, $this->getJson('/api/v1/oggi')->json('data.documenti.perizie_da_validare'));
    }

    public function test_ogni_sezione_segue_i_permessi_e_il_tenant(): void
    {
        $this->ordine(['title' => 'In ritardo', 'planned_start' => now()->subDays(5)->toDateString(), 'planned_end' => now()->subDay()->toDateString()]);

        // Un altro studio con il suo ritardo: non si vede
        [$altra, $altroUtente] = $this->createTenantUser();
        $altraArea = $this->createArea($altra);
        WorkOrder::create([
            'tenant_id' => $altra->id, 'code' => 'ALTRO-0001', 'title' => 'Lavoro di un altro studio', 'status' => 'planned',
            'area_id' => $altraArea->id, 'created_by' => $altroUtente->id, 'updated_by' => $altroUtente->id,
            'planned_start' => now()->subDays(5)->toDateString(), 'planned_end' => now()->subDay()->toDateString(),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $this->actingAsTenantUser($this->utente);

        $oggi = $this->getJson('/api/v1/oggi')->assertOk()->json('data');
        $this->assertSame(1, $oggi['conteggi']['lavori_ritardo']);
        $this->assertStringNotContainsString('ALTRO-0001', json_encode($oggi));

        // Chi vede solo il censimento: niente lavori, niente portali, ma il
        // campo e i documenti si'
        $this->postJson('/api/v1/roles', ['nome' => 'Solo censimento', 'permessi' => ['assets.view']])->assertCreated();
        $lettore = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        $lettore->assignRole('Solo censimento');
        $this->actingAsTenantUser($lettore->fresh());

        $oggi = $this->getJson('/api/v1/oggi')->assertOk()->json('data');
        $this->assertSame(0, $oggi['conteggi']['lavori_ritardo']);
        $this->assertSame([], array_filter($oggi['voci'], fn ($v) => $v['tipo'] === 'lavoro'));
        $this->assertNotNull($oggi['campo']);
        $this->assertNotNull($oggi['documenti']);
        $this->assertNull($oggi['portali']);

        // Il cliente del portale non entra
        [, $cliente] = $this->createTenantUser([], 'cliente');
        $this->actingAsTenantUser($cliente);
        $this->getJson('/api/v1/oggi')->assertForbidden();
    }
}
