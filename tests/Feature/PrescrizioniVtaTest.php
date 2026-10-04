<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Dalle prescrizioni di una VTA all'agenda (punto 7 del committente,
 * 04/10/2026): la valutazione porta la data entro cui fare l'intervento,
 * l'elenco mostra le prescrizioni dell'ultima VTA di ogni albero con
 * l'ordine che ne e' nato, un clic crea l'ordine (uno per valutazione, mai
 * doppioni), la lavorazione si riconosce dal testo, Oggi conta le scadute.
 */
class PrescrizioniVtaTest extends TestCase
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

    private function albero(string $codice = 'ALB-0001'): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id, 'object_type_id' => $this->tipoAlbero->id, 'census_code' => $codice, 'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    private function valuta(string $albero, ?string $prescrizione, ?string $entro, string $data = '2026-09-01', string $classe = 'C'): array
    {
        return $this->postJson("/api/v1/assets/{$albero}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => $data, 'failure_class' => $classe,
            'outcome' => $prescrizione ? 'prescriptions' : 'monitor',
            'prescriptions' => $prescrizione, 'prescriptions_due_on' => $entro,
        ])->assertCreated()->json('data');
    }

    public function test_la_prescrizione_con_la_sua_data_diventa_un_ordine_una_volta_sola(): void
    {
        $albero = $this->albero();
        $entro = now('Europe/Rome')->addDays(20)->toDateString();
        $valutazione = $this->valuta($albero, "Rimonda del secco nella prossima stagione di riposo vegetativo\nRiduzione della chioma del 20%", $entro);
        $this->assertSame($entro, substr($valutazione['prescriptions_due_on'], 0, 10));

        $righe = $this->getJson('/api/v1/vta/prescrizioni?aperte=1')->assertOk()->json('data');
        $this->assertCount(1, $righe);
        $this->assertSame('ALB-0001', $righe[0]['census_code']);
        $this->assertNull($righe[0]['work_order']);
        $this->assertFalse($righe[0]['scaduta']);

        // L'anteprima conta esattamente quello che la conferma crea
        $prova = $this->postJson('/api/v1/vta/prescrizioni', ['prova' => 1])->assertOk()->json('data');
        $this->assertCount(1, $prova['creati']);
        $this->assertSame(0, WorkOrder::query()->where('origin', 'vta_prescription')->count());

        $esito = $this->postJson('/api/v1/vta/prescrizioni', ['assessment_ids' => [$valutazione['id']]])->assertOk()->json('data');
        $this->assertCount(1, $esito['creati']);
        $ordine = WorkOrder::query()->where('origin', 'vta_prescription')->where('origin_id', $valutazione['id'])->first();
        $this->assertNotNull($ordine);
        $this->assertSame($esito['creati'][0]['ordine'], $ordine->code);
        $this->assertSame('planned', $ordine->status);
        $this->assertSame($entro, $ordine->planned_start?->toDateString());
        $this->assertStringContainsString('Prescrizione VTA - ALB-0001', $ordine->title);
        $this->assertStringContainsString('Rimonda del secco', $ordine->description);
        $this->assertStringContainsString('Da eseguire entro il', $ordine->description);
        // Senza una lavorazione riconoscibile nel listino nasce quella generica
        $this->assertSame('PRE-VTA', $ordine->workType->code);
        $riga = WorkOrderAsset::query()->where('work_order_id', $ordine->id)->first();
        $this->assertSame($albero, $riga->asset_id);
        $this->assertStringContainsString('Rimonda del secco', $riga->notes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vta.prescription.generated']);

        // Rilanciare non crea doppioni e lo dice
        $secondo = $this->postJson('/api/v1/vta/prescrizioni', ['assessment_ids' => [$valutazione['id']]])->assertOk()->json('data');
        $this->assertCount(0, $secondo['creati']);
        $this->assertStringContainsString('già in agenda con '.$ordine->code, $secondo['saltati'][0]['motivo']);
        $this->assertSame(1, WorkOrder::query()->where('origin', 'vta_prescription')->count());

        // L'elenco: la riga ora porta l'ordine; fra le aperte non c'e' piu'
        $tutte = $this->getJson('/api/v1/vta/prescrizioni')->assertOk()->json('data');
        $this->assertSame($ordine->code, $tutte[0]['work_order']['code']);
        $this->assertCount(0, $this->getJson('/api/v1/vta/prescrizioni?aperte=1')->assertOk()->json('data'));

        // La cronologia dell'albero lo racconta come lavoro nato dalla prescrizione
        $eventi = $this->getJson("/api/v1/assets/{$albero}/cronologia")->assertOk()->json('data.eventi');
        $lavoro = collect($eventi)->first(fn ($e) => $e['tipo'] === 'lavoro');
        $this->assertSame('vta_prescription', $lavoro['origine']);
    }

    public function test_la_lavorazione_si_riconosce_dal_testo_della_prescrizione(): void
    {
        $potatura = WorkType::create(['tenant_id' => $this->organizzazione->id, 'code' => 'POT', 'name' => 'Potatura', 'category' => 'verde verticale', 'unit' => 'cad', 'applicable_geometry' => 'P']);
        WorkType::create(['tenant_id' => $this->organizzazione->id, 'code' => 'POT-RID', 'name' => 'Potatura di riduzione', 'category' => 'verde verticale', 'unit' => 'cad', 'applicable_geometry' => 'P']);
        $a = $this->albero('ALB-0010');
        $b = $this->albero('ALB-0011');
        $va = $this->valuta($a, 'Potatura di riduzione della chioma sul lato strada', null);
        $vb = $this->valuta($b, 'Rimonda del secco e alleggerimento delle branche', null);

        $this->postJson('/api/v1/vta/prescrizioni', ['assessment_ids' => [$va['id'], $vb['id']]])->assertOk();
        // Il nome piu' lungo che compare nel testo vince sulla famiglia
        $this->assertSame('POT-RID', WorkOrder::query()->where('origin_id', $va['id'])->first()->workType->code);
        // "rimonda" e "alleggerimento" sono potature: si usa la lavorazione della famiglia
        $this->assertSame($potatura->id, WorkOrder::query()->where('origin_id', $vb['id'])->first()->work_type_id);
    }

    public function test_conta_l_ultima_valutazione_e_una_senza_prescrizioni_chiude_le_vecchie(): void
    {
        $albero = $this->albero();
        $this->valuta($albero, 'Consolidamento della biforcazione', now('Europe/Rome')->subDays(5)->toDateString(), '2026-06-01');
        $this->assertCount(1, $this->getJson('/api/v1/vta/prescrizioni?aperte=1')->json('data'));

        $this->valuta($albero, null, null, '2026-09-15', 'B');
        $this->assertCount(0, $this->getJson('/api/v1/vta/prescrizioni?aperte=1')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/vta/prescrizioni')->json('data'));
        $this->assertCount(0, $this->postJson('/api/v1/vta/prescrizioni', ['prova' => 1])->json('data.creati'));
    }

    public function test_oggi_elenca_le_prescrizioni_scadute_e_senza_data_finche_non_hanno_un_ordine(): void
    {
        $scaduto = $this->albero('ALB-0020');
        $vs = $this->valuta($scaduto, 'Rimonda del secco', now('Europe/Rome')->subDays(10)->toDateString());
        $senzaData = $this->albero('ALB-0021');
        $this->valuta($senzaData, 'Trattamento endoterapico contro la processionaria', null);
        $lontano = $this->albero('ALB-0022');
        $this->valuta($lontano, 'Riduzione della chioma', now('Europe/Rome')->addDays(200)->toDateString());

        $oggi = $this->getJson('/api/v1/oggi')->assertOk()->json('data');
        $voci = collect($oggi['voci'])->where('tipo', 'prescrizione');
        $this->assertCount(2, $voci, 'scaduta e senza data entrano; quella fra 200 giorni no');
        $this->assertSame('ritardo', $voci->firstWhere('chiave', 'prescrizione:'.$vs['id'])['urgenza']);
        $this->assertSame(3, $oggi['conteggi']['prescrizioni_aperte']);
        $this->assertSame(1, $oggi['conteggi']['prescrizioni_scadute']);
        $this->assertSame(3, $oggi['conteggi']['famiglie']['lavori']);
        $this->assertStringContainsString('ALB-0020', $voci->firstWhere('chiave', 'prescrizione:'.$vs['id'])['titolo']);

        // Lo stesso numero lo da' il cruscotto della veste precedente
        $this->assertSame(3, $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data.prescrizioni.open_count'));

        $this->postJson('/api/v1/vta/prescrizioni', ['assessment_ids' => [$vs['id']]])->assertOk();
        $dopo = $this->getJson('/api/v1/oggi')->assertOk()->json('data');
        $this->assertCount(1, collect($dopo['voci'])->where('tipo', 'prescrizione'));
        $this->assertSame(2, $dopo['conteggi']['prescrizioni_aperte']);
    }

    public function test_senza_il_permesso_sui_lavori_si_legge_ma_non_si_crea(): void
    {
        $albero = $this->albero();
        $v = $this->valuta($albero, 'Rimonda del secco', null);
        $operatore = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $operatore->assignRole('operatore');
        $this->actingAsTenantUser($operatore);

        $this->getJson('/api/v1/vta/prescrizioni')->assertOk();
        $this->postJson('/api/v1/vta/prescrizioni', ['assessment_ids' => [$v['id']]])->assertForbidden();

        // Un'altra organizzazione non vede niente
        [, $altro] = $this->createTenantUser();
        $this->actingAsTenantUser($altro);
        $this->assertCount(0, $this->getJson('/api/v1/vta/prescrizioni')->assertOk()->json('data'));
    }
}
