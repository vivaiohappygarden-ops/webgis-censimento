<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Azioni su piu' elementi selezionati.
 *
 * Regola che vale per tutte: quello che non si e' potuto fare va detto con il
 * motivo. Un'azione di gruppo che salta righe in silenzio fa credere di aver
 * concluso un lavoro che invece e' rimasto a meta'.
 */
class AzioniMultipleTest extends TestCase
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

    private function creaElemento(): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    private function creaOrdine(string $stato = 'draft'): WorkOrder
    {
        $id = $this->postJson('/api/v1/work-orders', [
            'title' => 'Potatura di prova',
            'area_id' => $this->area->id,
        ])->assertCreated()->json('data.id');

        $ordine = WorkOrder::findOrFail($id);
        if ($stato !== 'draft') {
            $ordine->forceFill(['status' => $stato])->save();
        }

        return $ordine->fresh();
    }

    public function test_si_chiudono_piu_lavori_in_una_volta(): void
    {
        $uno = $this->creaOrdine('in_progress');
        $due = $this->creaOrdine('in_progress');

        $risposta = $this->postJson('/api/v1/azioni/chiudi-lavori', [
            'ids' => [$uno->id, $due->id],
        ])->assertOk();

        $this->assertCount(2, $risposta->json('data.completati'));
        $this->assertCount(0, $risposta->json('data.saltati'));
        $this->assertSame('completed', $uno->fresh()->status);
        $this->assertNotNull($uno->fresh()->completed_at);
    }

    public function test_i_lavori_che_non_si_possono_chiudere_vengono_riportati_con_il_motivo(): void
    {
        $chiudibile = $this->creaOrdine('in_progress');
        $gia = $this->creaOrdine('completed');
        $inesistente = '01a00000-0000-7000-8000-000000000000';

        $risposta = $this->postJson('/api/v1/azioni/chiudi-lavori', [
            'ids' => [$chiudibile->id, $gia->id, $inesistente],
        ])->assertOk();

        $this->assertCount(1, $risposta->json('data.completati'));
        $saltati = $risposta->json('data.saltati');
        $this->assertCount(2, $saltati);
        $motivi = implode(' ', array_column($saltati, 'motivo'));
        $this->assertStringContainsString('completato', $motivi);
        $this->assertStringContainsString('non trovato', $motivi);
    }

    public function test_si_nascondono_piu_elementi_dal_portale_in_una_volta(): void
    {
        $uno = $this->creaElemento();
        $due = $this->creaElemento();

        $this->postJson('/api/v1/azioni/modifica-elementi', [
            'ids' => [$uno, $due],
            'public_hidden' => true,
        ])->assertOk()->assertJsonCount(2, 'data.modificati');

        $this->assertTrue(Asset::findOrFail($uno)->public_hidden);
        $this->assertTrue(Asset::findOrFail($due)->public_hidden);
    }

    public function test_la_modifica_multipla_non_puo_cambiare_lo_stato(): void
    {
        $id = $this->creaElemento();

        // Lo stato ha il suo flusso: dalla modifica multipla non si tocca,
        // altrimenti l'abbattimento si potrebbe registrare senza data
        $this->postJson('/api/v1/azioni/modifica-elementi', [
            'ids' => [$id],
            'status' => 'removed',
        ])->assertStatus(422);

        $this->assertSame('active', Asset::findOrFail($id)->status);
    }

    public function test_le_schede_in_archivio_si_saltano_con_il_motivo(): void
    {
        $vivo = $this->creaElemento();
        $dismesso = $this->creaElemento();
        $this->patchJson("/api/v1/assets/{$dismesso}", ['status' => 'dismissed'])->assertOk();

        // Prova ed esecuzione passano dallo stesso giro: contano uguale
        foreach ([1, 0] as $prova) {
            $esito = $this->postJson('/api/v1/azioni/modifica-elementi', [
                'ids' => [$vivo, $dismesso],
                'public_hidden' => true,
                'prova' => $prova,
            ])->assertOk()->json('data');

            $this->assertCount(1, $esito['modificati']);
            $this->assertCount(1, $esito['saltati']);
            $this->assertSame($dismesso, $esito['saltati'][0]['id']);
            $this->assertStringContainsString('In archivio', $esito['saltati'][0]['motivo']);
        }

        // Il dismesso non e' stato toccato dall'esecuzione
        $this->assertFalse((bool) $this->getJson("/api/v1/assets/{$dismesso}")->json('data.public_hidden'));

        // E non si aggancia nemmeno a un ordine di lavoro
        $ordine = $this->creaOrdine();
        $esito = $this->postJson("/api/v1/azioni/lavori/{$ordine->id}/collega-elementi", [
            'ids' => [$vivo, $dismesso],
        ])->assertOk()->json('data');

        $this->assertCount(1, $esito['collegati']);
        $this->assertCount(1, $esito['saltati']);
        $this->assertStringContainsString('In archivio', $esito['saltati'][0]['motivo']);
        $this->assertDatabaseMissing('work_order_assets', ['asset_id' => $dismesso]);
    }

    public function test_senza_modifiche_indicate_non_si_fa_niente(): void
    {
        $this->postJson('/api/v1/azioni/modifica-elementi', [
            'ids' => [$this->creaElemento()],
        ])->assertStatus(422);
    }

    public function test_si_collegano_piu_elementi_a_un_ordine_di_lavoro(): void
    {
        $ordine = $this->creaOrdine();
        $uno = $this->creaElemento();
        $due = $this->creaElemento();

        $risposta = $this->postJson("/api/v1/azioni/lavori/{$ordine->id}/collega-elementi", [
            'ids' => [$uno, $due],
        ])->assertOk();

        $this->assertCount(2, $risposta->json('data.collegati'));
        $this->assertSame(2, $ordine->assets()->count());
    }

    public function test_gli_elementi_gia_collegati_non_si_duplicano(): void
    {
        $ordine = $this->creaOrdine();
        $id = $this->creaElemento();

        $this->postJson("/api/v1/azioni/lavori/{$ordine->id}/collega-elementi", ['ids' => [$id]])->assertOk();

        $risposta = $this->postJson("/api/v1/azioni/lavori/{$ordine->id}/collega-elementi", ['ids' => [$id]])
            ->assertOk();

        $this->assertCount(0, $risposta->json('data.collegati'));
        $this->assertStringContainsString('presente', $risposta->json('data.saltati.0.motivo'));
        $this->assertSame(1, $ordine->assets()->count());
    }

    public function test_su_un_ordine_chiuso_non_si_aggiungono_elementi(): void
    {
        $ordine = $this->creaOrdine('completed');

        $this->postJson("/api/v1/azioni/lavori/{$ordine->id}/collega-elementi", [
            'ids' => [$this->creaElemento()],
        ])->assertStatus(409);
    }

    public function test_c_e_un_tetto_al_numero_di_elementi_per_richiesta(): void
    {
        $troppi = array_fill(0, 501, '01a00000-0000-7000-8000-000000000000');

        $this->postJson('/api/v1/azioni/chiudi-lavori', ['ids' => $troppi])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_senza_permesso_non_si_agisce_in_blocco(): void
    {
        [, $cliente] = $this->createTenantUser(role: 'cliente');
        $this->actingAsTenantUser($cliente);

        $this->postJson('/api/v1/azioni/chiudi-lavori', ['ids' => ['01a00000-0000-7000-8000-000000000000']])
            ->assertForbidden();
        $this->postJson('/api/v1/azioni/modifica-elementi', [
            'ids' => ['01a00000-0000-7000-8000-000000000000'], 'public_hidden' => true,
        ])->assertForbidden();
    }

    // --- Prova a vuoto: si conta prima, si esegue dopo ----------------------

    public function test_la_prova_a_vuoto_conta_senza_toccare_niente(): void
    {
        $aperto = $this->creaOrdine('in_progress');
        $chiuso = $this->creaOrdine('completed');

        $esito = $this->postJson('/api/v1/azioni/chiudi-lavori', [
            'ids' => [$aperto->id, $chiuso->id], 'prova' => 1,
        ])->assertOk()->json('data');

        // Il conto e' giusto: uno passerebbe, uno no, con il motivo
        $this->assertCount(1, $esito['completati']);
        $this->assertCount(1, $esito['saltati']);
        $this->assertStringContainsString('completato', $esito['saltati'][0]['motivo']);

        // E niente e' stato toccato davvero
        $this->assertSame('in_progress', $aperto->fresh()->status);
    }

    public function test_la_prova_a_vuoto_della_modifica_non_scrive(): void
    {
        $elemento = $this->creaElemento();

        $this->postJson('/api/v1/azioni/modifica-elementi', [
            'ids' => [$elemento], 'public_hidden' => true, 'prova' => 1,
        ])->assertOk()->assertJsonCount(1, 'data.modificati');

        $this->assertFalse(Asset::findOrFail($elemento)->public_hidden);
    }

    public function test_la_prova_a_vuoto_del_collegamento_non_collega(): void
    {
        $ordine = $this->creaOrdine();
        $elemento = $this->creaElemento();

        $this->postJson("/api/v1/azioni/lavori/{$ordine->id}/collega-elementi", [
            'ids' => [$elemento], 'prova' => 1,
        ])->assertOk()->assertJsonCount(1, 'data.collegati');

        $this->assertSame(0, \App\Models\WorkOrderAsset::query()->count());

        // La conferma poi collega davvero
        $this->postJson("/api/v1/azioni/lavori/{$ordine->id}/collega-elementi", [
            'ids' => [$elemento],
        ])->assertOk();

        $this->assertSame(1, \App\Models\WorkOrderAsset::query()->count());
    }

    public function test_la_prova_a_vuoto_non_scrive_nel_registro_di_controllo(): void
    {
        $ordine = $this->creaOrdine();
        $elemento = $this->creaElemento();

        $this->postJson("/api/v1/azioni/lavori/{$ordine->id}/collega-elementi", [
            'ids' => [$elemento], 'prova' => 1,
        ])->assertOk();

        // Il registro racconta solo cose successe: la prova non ha collegato niente
        $this->assertSame(0, \App\Models\AuditLog::query()
            ->where('action', 'work_order.assets_attached')->count());

        $this->postJson("/api/v1/azioni/lavori/{$ordine->id}/collega-elementi", [
            'ids' => [$elemento],
        ])->assertOk();

        $this->assertSame(1, \App\Models\AuditLog::query()
            ->where('action', 'work_order.assets_attached')->count());
    }
}
