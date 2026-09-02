<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\WorkLog;
use App\Models\WorkOrder;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Incassi sui SAL: il programma REGISTRA fatture e incassi, non li emette.
 * Gli stati del documento restano tre (bozza/validato/fatturato):
 * "incassato" e' paid_at valorizzata, "in ritardo" e' una condizione
 * derivata (fatturato, non incassato, scadenza passata). Il quadro dei
 * crediti dice per committente quanto deve ancora entrare.
 */
class SalIncassiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $cliente;

    private $lavorazione;

    private $listinoId;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->actingAsTenantUser($this->utente);

        $this->cliente = Client::create([
            'tenant_id' => $this->organizzazione->id,
            'name' => 'Comune di Mentana', 'client_type' => 'public',
        ]);
        $this->lavorazione = WorkType::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => 'SFA', 'name' => 'Sfalcio', 'unit' => 'mq',
        ]);
        $this->listinoId = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-I', 'name' => 'Incassi'])
            ->json('data.id');
        $this->putJson("/api/v1/price-lists/{$this->listinoId}/items", [
            'items' => [['work_type_id' => $this->lavorazione->id, 'unit' => 'mq', 'unit_price' => 0.5]],
        ])->assertOk();
    }

    /** Un ordine completato valorizzabile: 1000 mq x 0,50 = 500 (+IVA 110 = 610). */
    private function ordineCompletato(?Client $cliente = null): WorkOrder
    {
        $ordine = WorkOrder::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => 'ODL-2026-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'Sfalcio parco',
            'status' => 'completed',
            'client_id' => ($cliente ?? $this->cliente)->id,
            'work_type_id' => $this->lavorazione->id,
            'price_list_id' => $this->listinoId,
        ]);
        WorkOrder::query()->whereKey($ordine->id)->update(['completed_at' => '2026-08-05 10:00:00']);
        WorkLog::create([
            'tenant_id' => $this->organizzazione->id,
            'work_order_id' => $ordine->id,
            'operator_id' => $this->utente->id,
            'started_at' => now(),
            'quantity' => 1000, 'unit' => 'mq', 'man_hours' => 5,
        ]);

        return $ordine->fresh();
    }

    /**
     * Prepara e valida un SAL da 610 euro (un ordine nuovo). Ogni chiamata
     * crea prima il proprio ordine: quelli precedenti sono gia' in un SAL.
     */
    private function salValidato(?Client $cliente = null): string
    {
        $this->ordineCompletato($cliente);
        $id = $this->postJson('/api/v1/sals', [
            'client_id' => ($cliente ?? $this->cliente)->id,
            'period_from' => '2026-08-01',
            'period_to' => '2026-08-31',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/sals/{$id}/valida")->assertOk();

        return $id;
    }

    public function test_la_fattura_si_registra_solo_su_un_sal_validato(): void
    {
        // In bozza no: prima si valida
        $this->ordineCompletato();
        $bozza = $this->postJson('/api/v1/sals', [
            'client_id' => $this->cliente->id,
            'period_from' => '2026-08-01', 'period_to' => '2026-08-31',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/sals/{$bozza}/fatturato", ['invoice_ref' => 'FT 1/2026'])
            ->assertStatus(409);

        $this->postJson("/api/v1/sals/{$bozza}/valida")->assertOk();
        $dopo = $this->postJson("/api/v1/sals/{$bozza}/fatturato", [
            'invoice_ref' => 'FT 12/2026',
            'invoiced_at' => '2026-08-20',
            'payment_due_at' => '2026-09-19',
        ])->assertOk()->json('data');

        // Gli estremi tornano come giorni di calendario, tali e quali
        $this->assertSame('fatturato', $dopo['status']);
        $this->assertSame('FT 12/2026', $dopo['invoice_ref']);
        $this->assertSame('2026-08-20', $dopo['invoiced_at']);
        $this->assertSame('2026-09-19', $dopo['payment_due_at']);
        $this->assertNull($dopo['paid_at']);

        // Due volte no: la fattura e' gia' registrata
        $this->postJson("/api/v1/sals/{$bozza}/fatturato", ['invoice_ref' => 'FT 13/2026'])
            ->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sal.invoiced', 'subject_id' => $bozza]);
    }

    public function test_senza_data_la_fattura_porta_il_giorno_italiano_di_oggi(): void
    {
        $id = $this->salValidato();
        $dopo = $this->postJson("/api/v1/sals/{$id}/fatturato")->assertOk()->json('data');

        $this->assertSame(now('Europe/Rome')->toDateString(), $dopo['invoiced_at']);
        $this->assertNull($dopo['payment_due_at']);
    }

    public function test_la_scadenza_prima_della_fattura_e_respinta(): void
    {
        $id = $this->salValidato();

        $this->postJson("/api/v1/sals/{$id}/fatturato", [
            'invoiced_at' => '2026-08-20',
            'payment_due_at' => '2026-08-19',
        ])->assertUnprocessable()->assertJsonValidationErrors(['payment_due_at']);

        // Anche senza data esplicita: la scadenza nel passato precederebbe
        // il giorno di oggi, che e' la data assunta della fattura
        $this->postJson("/api/v1/sals/{$id}/fatturato", [
            'payment_due_at' => now('Europe/Rome')->subDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['payment_due_at']);

        // Un formato che non e' un giorno di calendario non passa
        $this->postJson("/api/v1/sals/{$id}/fatturato", [
            'invoiced_at' => '20/08/2026',
        ])->assertUnprocessable()->assertJsonValidationErrors(['invoiced_at']);
    }

    public function test_l_incasso_si_registra_solo_su_un_sal_fatturato(): void
    {
        $id = $this->salValidato();

        // Su un validato non ancora fatturato, no
        $this->postJson("/api/v1/sals/{$id}/incasso", ['paid_at' => now('Europe/Rome')->toDateString()])
            ->assertStatus(409);

        $this->postJson("/api/v1/sals/{$id}/fatturato", ['invoiced_at' => '2026-08-20'])->assertOk();
        $dopo = $this->postJson("/api/v1/sals/{$id}/incasso", [
            'paid_at' => now('Europe/Rome')->toDateString(),
            'paid_note' => 'bonifico n. 123',
        ])->assertOk()->json('data');

        // Lo stato NON cambia: incassato e' un fatto, non uno stato
        $this->assertSame('fatturato', $dopo['status']);
        $this->assertSame(now('Europe/Rome')->toDateString(), $dopo['paid_at']);
        $this->assertSame('bonifico n. 123', $dopo['paid_note']);

        // Un incasso gia' registrato non si sovrascrive: prima si annulla
        $this->postJson("/api/v1/sals/{$id}/incasso", ['paid_at' => now('Europe/Rome')->toDateString()])
            ->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sal.paid', 'subject_id' => $id]);
    }

    public function test_le_date_incoerenti_dell_incasso_sono_respinte(): void
    {
        $id = $this->salValidato();
        $this->postJson("/api/v1/sals/{$id}/fatturato", ['invoiced_at' => '2026-08-20'])->assertOk();

        // Nel futuro no: un incasso si registra quando e' avvenuto
        $this->postJson("/api/v1/sals/{$id}/incasso", [
            'paid_at' => now('Europe/Rome')->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);

        // Prima della fattura no: i soldi non arrivano prima del documento
        $this->postJson("/api/v1/sals/{$id}/incasso", ['paid_at' => '2026-08-10'])
            ->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);

        // E senza data proprio non si parte
        $this->postJson("/api/v1/sals/{$id}/incasso", ['paid_note' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);
    }

    public function test_l_annullo_riporta_il_sal_a_fatturato_da_incassare(): void
    {
        $id = $this->salValidato();
        $this->postJson("/api/v1/sals/{$id}/fatturato", ['invoiced_at' => '2026-08-20'])->assertOk();
        $this->postJson("/api/v1/sals/{$id}/incasso", [
            'paid_at' => now('Europe/Rome')->toDateString(), 'paid_note' => 'errore di persona',
        ])->assertOk();

        $dopo = $this->deleteJson("/api/v1/sals/{$id}/incasso")->assertOk()->json('data');
        $this->assertSame('fatturato', $dopo['status']);
        $this->assertNull($dopo['paid_at']);
        $this->assertNull($dopo['paid_note']);

        // L'audit tiene i valori tolti: un fatto contabile non sparisce senza traccia
        $this->assertDatabaseHas('audit_logs', ['action' => 'sal.payment_cancelled', 'subject_id' => $id]);

        // Senza un incasso registrato non c'e' niente da annullare
        $this->deleteJson("/api/v1/sals/{$id}/incasso")->assertStatus(409);
    }

    public function test_il_quadro_dei_crediti_con_numeri_costruiti_a_tavolino(): void
    {
        $oggi = now('Europe/Rome');
        $altro = Client::create([
            'tenant_id' => $this->organizzazione->id,
            'name' => 'Provincia di Roma', 'client_type' => 'public',
        ]);

        // Cliente A: un SAL validato (emesso 610) e uno fatturato con
        // scadenza futura (da incassare 610, non scaduto)
        $this->salValidato();
        $aCorrente = $this->salValidato();
        $this->postJson("/api/v1/sals/{$aCorrente}/fatturato", [
            'invoiced_at' => $oggi->toDateString(),
            'payment_due_at' => $oggi->copy()->addDays(30)->toDateString(),
        ])->assertOk();

        // Cliente A, anno scorso: fatturato e incassato allora. Non deve
        // entrare da nessuna parte: non e' un credito e non e' l'anno corrente
        $aVecchio = $this->salValidato();
        $this->postJson("/api/v1/sals/{$aVecchio}/fatturato", [
            'invoiced_at' => $oggi->copy()->subYear()->startOfYear()->toDateString(),
        ])->assertOk();
        $this->postJson("/api/v1/sals/{$aVecchio}/incasso", [
            'paid_at' => $oggi->copy()->subYear()->startOfYear()->addDays(10)->toDateString(),
        ])->assertOk();

        // Cliente B: uno scaduto da 10 giorni e uno incassato quest'anno
        $bScaduto = $this->salValidato($altro);
        $this->postJson("/api/v1/sals/{$bScaduto}/fatturato", [
            'invoice_ref' => 'FT 40/2026',
            'invoiced_at' => $oggi->copy()->subDays(40)->toDateString(),
            'payment_due_at' => $oggi->copy()->subDays(10)->toDateString(),
        ])->assertOk();
        $bIncassato = $this->salValidato($altro);
        $this->postJson("/api/v1/sals/{$bIncassato}/fatturato", [
            'invoiced_at' => $oggi->copy()->subDays(40)->toDateString(),
        ])->assertOk();
        $this->postJson("/api/v1/sals/{$bIncassato}/incasso", ['paid_at' => $oggi->toDateString()])->assertOk();

        $quadro = $this->getJson('/api/v1/sals/crediti')->assertOk()->json('data');

        // Ogni SAL vale 610 (500 + IVA 22%): i totali si sommano da li'
        $this->assertSame($oggi->year, $quadro['anno']);
        $this->assertEquals(610.0, $quadro['totale']['emesso']);
        $this->assertEquals(1220.0, $quadro['totale']['da_incassare']);
        $this->assertEquals(610.0, $quadro['totale']['scaduto']);
        $this->assertEquals(10, $quadro['totale']['ritardo_medio_giorni']);
        $this->assertEquals(610.0, $quadro['totale']['incassato_anno']);

        // Per committente: prima B (ha lo scaduto), poi A
        $righe = collect($quadro['per_committente']);
        $this->assertCount(2, $righe);
        $this->assertSame('Provincia di Roma', $righe[0]['client']['name']);
        $this->assertEquals(610.0, $righe[0]['scaduto']);
        $this->assertEquals(610.0, $righe[0]['da_incassare']);
        $this->assertEquals(10, $righe[0]['ritardo_medio_giorni']);
        $this->assertEquals(610.0, $righe[0]['incassato_anno']);

        $this->assertSame('Comune di Mentana', $righe[1]['client']['name']);
        $this->assertEquals(610.0, $righe[1]['emesso']);
        $this->assertEquals(610.0, $righe[1]['da_incassare']);
        $this->assertEquals(0.0, $righe[1]['scaduto']);
        $this->assertNull($righe[1]['ritardo_medio_giorni']);
        // L'incasso dell'anno scorso non conta nell'anno corrente
        $this->assertEquals(0.0, $righe[1]['incassato_anno']);
    }

    public function test_lo_scaduto_sparisce_quando_l_incasso_viene_registrato(): void
    {
        $oggi = now('Europe/Rome');
        $id = $this->salValidato();
        $this->postJson("/api/v1/sals/{$id}/fatturato", [
            'invoiced_at' => $oggi->copy()->subDays(40)->toDateString(),
            'payment_due_at' => $oggi->copy()->subDays(15)->toDateString(),
        ])->assertOk();

        $prima = $this->getJson('/api/v1/sals/crediti')->assertOk()->json('data.totale');
        $this->assertEquals(610.0, $prima['scaduto']);
        $this->assertEquals(15, $prima['ritardo_medio_giorni']);

        // Anche l'elenco segnala il ritardo, con gli stessi giorni
        $inElenco = collect($this->getJson('/api/v1/sals')->assertOk()->json('data'))
            ->firstWhere('id', $id);
        $this->assertEquals(15, $inElenco['ritardo_giorni']);

        $this->postJson("/api/v1/sals/{$id}/incasso", ['paid_at' => $oggi->toDateString()])->assertOk();

        $dopo = $this->getJson('/api/v1/sals/crediti')->assertOk()->json('data.totale');
        $this->assertEquals(0.0, $dopo['scaduto']);
        $this->assertEquals(0.0, $dopo['da_incassare']);
        $this->assertNull($dopo['ritardo_medio_giorni']);
        $this->assertEquals(610.0, $dopo['incassato_anno']);
    }

    public function test_permessi_e_recinto_del_tenant(): void
    {
        $id = $this->salValidato();
        $this->postJson("/api/v1/sals/{$id}/fatturato")->assertOk();

        // L'operatore non gestisce i lavori: niente incassi, niente quadro
        $operatoreId = $this->postJson('/api/v1/users', [
            'name' => 'Operatore', 'email' => 'operatore-incassi@example.com', 'role' => 'operatore',
        ])->assertCreated()->json('data.id');
        $this->actingAsTenantUser(\App\Models\User::withoutGlobalScopes()->findOrFail($operatoreId));
        $this->getJson('/api/v1/sals/crediti')->assertForbidden();
        $this->postJson("/api/v1/sals/{$id}/incasso", ['paid_at' => now('Europe/Rome')->toDateString()])
            ->assertForbidden();

        // Un'altra organizzazione non tocca questo SAL e ha un quadro vuoto
        [, $estraneo] = $this->createTenantUser();
        $this->actingAsTenantUser($estraneo);
        $this->postJson("/api/v1/sals/{$id}/incasso", ['paid_at' => now('Europe/Rome')->toDateString()])
            ->assertNotFound();
        $this->deleteJson("/api/v1/sals/{$id}/incasso")->assertNotFound();
        $vuoto = $this->getJson('/api/v1/sals/crediti')->assertOk()->json('data');
        $this->assertCount(0, $vuoto['per_committente']);
        $this->assertEquals(0, $vuoto['totale']['da_incassare']);
    }
}
