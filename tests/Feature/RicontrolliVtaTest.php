<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Il ricontrollo VTA diventa un ordine di lavoro.
 *
 * La data che il tecnico prescrive nella valutazione entra in agenda invece
 * di restare un promemoria da leggere: anteprima ed esecuzione passano dallo
 * stesso metodo (il pre-conteggio non puo' mentire), la generazione e'
 * idempotente per valutazione e chi resta fuori lo sa, con il motivo.
 */
class RicontrolliVtaTest extends TestCase
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

    /** Un albero con la sua ultima valutazione e la scadenza voluta. */
    private function alberoConScadenza(?string $scadenza, string $sopralluogo = '2026-01-15', string $classe = 'C'): string
    {
        $id = $this->creaAlbero();
        $this->valuta($id, $scadenza, $sopralluogo, $classe);

        return $id;
    }

    private function valuta(string $assetId, ?string $scadenza, string $sopralluogo, string $classe = 'C'): array
    {
        return $this->postJson("/api/v1/assets/{$assetId}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => $sopralluogo,
            'failure_class' => $classe,
            // La data esplicita non viene toccata dal calcolo automatico;
            // per il caso "senza ricontrollo" serve una classe che non lo
            // preveda (D: l'albero va abbattuto, non ricontrollato)
            ...($scadenza !== null ? ['next_check_due' => $scadenza] : []),
        ])->assertCreated()->json('data');
    }

    public function test_un_ricontrollo_scaduto_diventa_un_ordine_di_lavoro(): void
    {
        $albero = $this->alberoConScadenza(now()->subDays(20)->toDateString());

        $risposta = $this->postJson('/api/v1/vta/ricontrolli', ['prova' => 0])->assertOk();

        $risposta->assertJsonCount(1, 'data.creati');
        $ordine = WorkOrder::query()->where('origin', 'vta_recheck')->firstOrFail();

        $this->assertSame('planned', $ordine->status);
        $this->assertStringStartsWith('Ricontrollo VTA - ', $ordine->title);
        $this->assertSame(
            now()->subDays(20)->toDateString(),
            $ordine->planned_start->toDateString(),
            'La data dell ordine e quella prescritta dalla valutazione',
        );
        // L'albero e' attaccato all'ordine: in campo si deve sapere quale
        $this->assertDatabaseHas('work_order_assets', [
            'work_order_id' => $ordine->id, 'asset_id' => $albero,
        ]);
        $this->assertSame(1.0, (float) WorkOrderAsset::query()->where('work_order_id', $ordine->id)->value('planned_quantity'));
    }

    public function test_l_anteprima_conta_esattamente_quello_che_la_conferma_crea(): void
    {
        $this->alberoConScadenza(now()->subDays(5)->toDateString());
        $this->alberoConScadenza(now()->addDays(10)->toDateString());
        // Oltre i 30 giorni di orizzonte: non riguarda questo giro
        $this->alberoConScadenza(now()->addMonths(6)->toDateString());

        $prova = $this->postJson('/api/v1/vta/ricontrolli', ['prova' => 1])->assertOk();
        $prova->assertJsonCount(2, 'data.creati');
        // La prova non scrive: nessun ordine, nessuna lavorazione inventata
        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertDatabaseMissing('work_types', ['code' => 'RIC-VTA']);

        $conferma = $this->postJson('/api/v1/vta/ricontrolli', [
            'asset_ids' => collect($prova->json('data.creati'))->pluck('asset_id')->all(),
            'prova' => 0,
        ])->assertOk();

        $conferma->assertJsonCount(2, 'data.creati');
        $this->assertSame(2, WorkOrder::query()->where('origin', 'vta_recheck')->count());
    }

    public function test_rilanciare_non_crea_doppioni(): void
    {
        $this->alberoConScadenza(now()->subDays(3)->toDateString());

        $this->postJson('/api/v1/vta/ricontrolli', ['prova' => 0])->assertOk()->assertJsonCount(1, 'data.creati');

        $secondo = $this->postJson('/api/v1/vta/ricontrolli', ['prova' => 0])->assertOk();
        $secondo->assertJsonCount(0, 'data.creati')->assertJsonCount(1, 'data.saltati');
        $this->assertStringContainsString("gia' presente", $secondo->json('data.saltati.0.motivo'));
        $this->assertSame(1, WorkOrder::query()->where('origin', 'vta_recheck')->count());
    }

    public function test_un_ordine_annullato_copre_comunque_la_sua_valutazione(): void
    {
        $this->alberoConScadenza(now()->subDay()->toDateString());
        $this->postJson('/api/v1/vta/ricontrolli', ['prova' => 0])->assertOk();

        // Annullare e' stata una decisione: non si ripropone da sola
        DB::table('work_orders')->where('origin', 'vta_recheck')->update(['status' => 'cancelled']);

        $this->postJson('/api/v1/vta/ricontrolli', ['prova' => 0])->assertOk()
            ->assertJsonCount(0, 'data.creati')
            ->assertJsonCount(1, 'data.saltati');
    }

    public function test_conta_l_ultima_valutazione_non_la_prima(): void
    {
        $albero = $this->creaAlbero();
        $this->valuta($albero, now()->subDays(60)->toDateString(), '2026-01-10');
        // Una valutazione piu' recente sposta la scadenza: e' la sua parola
        // che conta, e l'ordine deve nascere da quella
        $nuovaScadenza = now()->subDays(2)->toDateString();
        $this->valuta($albero, $nuovaScadenza, '2026-06-10', 'B');

        $this->postJson('/api/v1/vta/ricontrolli', ['prova' => 0])->assertOk()->assertJsonCount(1, 'data.creati');

        $ordine = WorkOrder::query()->where('origin', 'vta_recheck')->firstOrFail();
        $this->assertSame($nuovaScadenza, $ordine->planned_start->toDateString());
        $this->assertStringContainsString('10/06/2026', (string) $ordine->description);
    }

    public function test_gli_esclusi_sono_dichiarati_uno_per_uno(): void
    {
        $senzaValutazione = $this->creaAlbero();
        $lontano = $this->alberoConScadenza(now()->addMonths(8)->toDateString());
        $inArchivio = $this->alberoConScadenza(now()->subDays(4)->toDateString());
        DB::table('assets')->where('id', $inArchivio)->update(['status' => 'removed']);

        $risposta = $this->postJson('/api/v1/vta/ricontrolli', [
            'asset_ids' => [$senzaValutazione, $lontano, $inArchivio],
            'prova' => 1,
        ])->assertOk();

        $risposta->assertJsonCount(0, 'data.creati')->assertJsonCount(3, 'data.saltati');
        $motivi = collect($risposta->json('data.saltati'))->pluck('motivo', 'asset_id');
        $this->assertStringContainsString('Nessuna valutazione', $motivi[$senzaValutazione]);
        $this->assertStringContainsString('oltre la data scelta', $motivi[$lontano]);
        $this->assertStringContainsString('In archivio', $motivi[$inArchivio]);
    }

    public function test_una_valutazione_senza_data_di_ricontrollo_lo_dice(): void
    {
        $albero = $this->creaAlbero();
        // Classe D: l'albero va abbattuto, il ricontrollo non si programma
        $valutazione = $this->valuta($albero, null, '2026-05-05', 'D');
        $this->assertNull($valutazione['next_check_due']);

        $risposta = $this->postJson('/api/v1/vta/ricontrolli', [
            'asset_ids' => [$albero], 'prova' => 1,
        ])->assertOk();

        $risposta->assertJsonCount(0, 'data.creati');
        $this->assertStringContainsString('non fissa una data', $risposta->json('data.saltati.0.motivo'));
    }

    public function test_la_data_limite_si_puo_allargare(): void
    {
        $this->alberoConScadenza(now()->addMonths(3)->toDateString());

        // Di serie si guarda a 30 giorni: fuori orizzonte, niente da fare
        $this->postJson('/api/v1/vta/ricontrolli', ['prova' => 1])->assertOk()->assertJsonCount(0, 'data.creati');

        $this->postJson('/api/v1/vta/ricontrolli', [
            'entro' => now()->addMonths(4)->toDateString(), 'prova' => 1,
        ])->assertOk()->assertJsonCount(1, 'data.creati');
    }

    public function test_senza_il_permesso_sui_lavori_non_si_mette_niente_in_agenda(): void
    {
        [$organizzazione, $operatore] = $this->createTenantUser([], 'operatore');
        $this->actingAsTenantUser($operatore);

        $this->postJson('/api/v1/vta/ricontrolli', ['prova' => 1])->assertForbidden();
    }

    public function test_il_cruscotto_oggi_distingue_i_ricontrolli_gia_in_agenda(): void
    {
        $this->alberoConScadenza(now()->subDays(10)->toDateString());
        $this->alberoConScadenza(now()->addDays(5)->toDateString());

        $prima = $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data.vta');
        $this->assertSame(1, $prima['overdue_count']);
        $this->assertSame(1, $prima['due_soon_count']);
        $this->assertSame(2, $prima['without_order_count']);

        $this->postJson('/api/v1/vta/ricontrolli', ['prova' => 0])->assertOk();

        $dopo = $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data.vta');
        $this->assertSame(0, $dopo['without_order_count'], 'Messi in agenda, non sono piu\' da programmare');
        $this->assertNotNull($dopo['rows'][0]['work_order_code']);
    }
}
