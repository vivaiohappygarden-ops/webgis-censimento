<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Sal;
use App\Models\WorkLog;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\TestCase;

/**
 * SAL, stati di avanzamento lavori: la bozza fotografa i completati del
 * periodo valorizzati dal listino, ogni ordine sta in un solo SAL, l'IVA e'
 * per riga, la validazione numera e congela, "Fatturato" e' solo un segno.
 */
class SalTest extends TestCase
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
        $this->listinoId = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-S', 'name' => 'SAL'])
            ->json('data.id');
        $this->putJson("/api/v1/price-lists/{$this->listinoId}/items", [
            'items' => [['work_type_id' => $this->lavorazione->id, 'unit' => 'mq', 'unit_price' => 0.5]],
        ])->assertOk();
    }

    /** Un ordine completato, di serie valorizzabile (1000 mq x 0,50 = 500). */
    private function ordineCompletato(array $campi = [], ?array $log = ['quantity' => 1000, 'unit' => 'mq', 'man_hours' => 5], string $completatoIl = '2026-08-05 10:00:00'): WorkOrder
    {
        $ordine = WorkOrder::create(array_merge([
            'tenant_id' => $this->organizzazione->id,
            'code' => 'ODL-2026-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'Sfalcio parco',
            'status' => 'completed',
            'client_id' => $this->cliente->id,
            'work_type_id' => $this->lavorazione->id,
            'price_list_id' => $this->listinoId,
        ], $campi));
        WorkOrder::query()->whereKey($ordine->id)->update(['completed_at' => $completatoIl]);
        if ($log !== null) {
            WorkLog::create(array_merge([
                'tenant_id' => $this->organizzazione->id,
                'work_order_id' => $ordine->id,
                'operator_id' => $this->utente->id,
                'started_at' => now(),
            ], $log));
        }

        return $ordine->fresh();
    }

    private function creaSal(array $campi = []): array
    {
        return $this->postJson('/api/v1/sals', array_merge([
            'client_id' => $this->cliente->id,
            'period_from' => '2026-08-01',
            'period_to' => '2026-08-31',
        ], $campi))->assertCreated()->json('data');
    }

    public function test_la_bozza_fotografa_i_completati_del_periodo(): void
    {
        $valorizzato = $this->ordineCompletato();
        $senzaListino = $this->ordineCompletato(['price_list_id' => null, 'title' => 'Senza listino']);
        // Fuori periodo, non completato, di un altro committente: restano fuori
        $this->ordineCompletato(completatoIl: '2026-07-10 10:00:00');
        WorkOrder::create([
            'tenant_id' => $this->organizzazione->id, 'code' => 'ODL-2026-0001',
            'title' => 'In corso', 'status' => 'in_progress', 'client_id' => $this->cliente->id,
        ]);
        $altro = Client::create(['tenant_id' => $this->organizzazione->id, 'name' => 'Altro', 'client_type' => 'private']);
        $this->ordineCompletato(['client_id' => $altro->id]);

        $sal = $this->creaSal();

        $this->assertSame('bozza', $sal['status']);
        $this->assertNull($sal['code']);
        $this->assertCount(2, $sal['items']);

        $righe = collect($sal['items']);
        $rigaOk = $righe->firstWhere('work_order_id', $valorizzato->id);
        $this->assertEquals(500.0, $rigaOk['imponibile']);
        $this->assertEquals(22.0, $rigaOk['vat_rate']);
        $this->assertStringContainsString('Sfalcio', $rigaOk['descrizione']);

        $rigaSenza = $righe->firstWhere('work_order_id', $senzaListino->id);
        $this->assertEquals(0.0, $rigaSenza['imponibile']);
        $this->assertSame('Senza listino o lavorazione: importo da definire.', $rigaSenza['nota']);

        // I conti: 500 imponibile, IVA 22% = 110, totale 610
        $this->assertEquals(500.0, $sal['totali']['imponibile_righe']);
        $this->assertEquals(110.0, $sal['totali']['iva']);
        $this->assertEquals(610.0, $sal['totali']['totale']);
    }

    public function test_i_confini_del_periodo_sono_giorni_italiani_come_nel_rendiconto(): void
    {
        // 22:30 UTC del 31/08 = 00:30 del 1/09 in Italia: non e' agosto
        $this->ordineCompletato(completatoIl: '2026-08-31 22:30:00');

        $this->postJson('/api/v1/sals', [
            'client_id' => $this->cliente->id,
            'period_from' => '2026-08-01', 'period_to' => '2026-08-31',
        ])->assertUnprocessable();

        $settembre = $this->creaSal(['period_from' => '2026-09-01', 'period_to' => '2026-09-30']);
        $this->assertCount(1, $settembre['items']);
    }

    public function test_un_ordine_sta_in_un_solo_sal(): void
    {
        $this->ordineCompletato();
        $primo = $this->creaSal();

        // Lo stesso periodo non trova piu' niente: l'ordine e' gia' rendicontato
        $this->postJson('/api/v1/sals', [
            'client_id' => $this->cliente->id,
            'period_from' => '2026-08-01', 'period_to' => '2026-08-31',
        ])->assertUnprocessable()->assertJsonValidationErrors(['period_from']);

        // Eliminata la bozza, l'ordine torna libero
        $this->deleteJson("/api/v1/sals/{$primo['id']}")->assertNoContent();
        $this->creaSal();
    }

    public function test_togliere_una_riga_libera_l_ordine(): void
    {
        $this->ordineCompletato();
        $liberato = $this->ordineCompletato(['title' => 'Da liberare']);
        $sal = $this->creaSal();
        $riga = collect($sal['items'])->firstWhere('work_order_id', $liberato->id);

        $dopo = $this->deleteJson("/api/v1/sals/{$sal['id']}/items/{$riga['id']}")
            ->assertOk()->json('data');
        $this->assertCount(1, $dopo['items']);

        // L'ordine liberato entra nel SAL successivo
        $secondo = $this->creaSal();
        $this->assertSame($liberato->id, $secondo['items'][0]['work_order_id']);
    }

    public function test_l_iva_si_sceglie_riga_per_riga(): void
    {
        $this->ordineCompletato();
        $ridotto = $this->ordineCompletato(['title' => 'Aliquota ridotta'], ['quantity' => 600, 'unit' => 'mq']);
        $sal = $this->creaSal();
        $rigaRidotta = collect($sal['items'])->firstWhere('work_order_id', $ridotto->id);

        $dopo = $this->patchJson("/api/v1/sals/{$sal['id']}", [
            'items' => [['id' => $rigaRidotta['id'], 'vat_rate' => 10]],
        ])->assertOk()->json('data');

        // 500 al 22% (IVA 110) + 300 al 10% (IVA 30): due voci nel riepilogo
        $voci = collect($dopo['totali']['per_aliquota']);
        $this->assertEquals([10.0, 22.0], $voci->pluck('aliquota')->all());
        $this->assertEquals(30.0, $voci->firstWhere('aliquota', 10.0)['iva']);
        $this->assertEquals(110.0, $voci->firstWhere('aliquota', 22.0)['iva']);
        $this->assertEquals(940.0, $dopo['totali']['totale']);
    }

    public function test_le_spese_generali_si_fotografano_e_si_ripartiscono_per_aliquota(): void
    {
        // Un SAL preparato con le spese generali spente non le prende
        $this->ordineCompletato();
        $spento = $this->creaSal();
        $this->assertNull($spento['overhead_pct']);
        $this->deleteJson("/api/v1/sals/{$spento['id']}")->assertNoContent();

        config(['sal.spese_generali_percento' => 10]);
        $this->ordineCompletato(['title' => 'Ridotto'], ['quantity' => 600, 'unit' => 'mq']);
        $sal = $this->creaSal();
        $this->assertEquals(10.0, $sal['overhead_pct']);

        $ridotto = collect($sal['items'])->first(fn ($r) => str_contains($r['descrizione'], 'Ridotto'));
        $sal = $this->patchJson("/api/v1/sals/{$sal['id']}", [
            'items' => [['id' => $ridotto['id'], 'vat_rate' => 10]],
        ])->assertOk()->json('data');

        // Righe 500 (22%) + 300 (10%) = 800; spese 80 ripartite 50 e 30
        $totali = $sal['totali'];
        $this->assertEquals(80.0, $totali['spese_generali']);
        $this->assertEquals(880.0, $totali['imponibile']);
        $voci = collect($totali['per_aliquota']);
        $this->assertEquals(330.0, $voci->firstWhere('aliquota', 10.0)['imponibile']);
        $this->assertEquals(33.0, $voci->firstWhere('aliquota', 10.0)['iva']);
        $this->assertEquals(550.0, $voci->firstWhere('aliquota', 22.0)['imponibile']);
        $this->assertEquals(121.0, $voci->firstWhere('aliquota', 22.0)['iva']);
        $this->assertEquals(1034.0, $totali['totale']);

        // Spegnere le spese dopo non tocca il SAL gia' preparato
        config(['sal.spese_generali_percento' => 0]);
        $riletto = $this->getJson("/api/v1/sals/{$sal['id']}")->assertOk()->json('data');
        $this->assertEquals(80.0, $riletto['totali']['spese_generali']);
    }

    public function test_la_validazione_numera_e_congela(): void
    {
        $this->ordineCompletato();
        $sal = $this->creaSal();

        $validato = $this->postJson("/api/v1/sals/{$sal['id']}/valida")->assertOk()->json('data');
        $anno = now('Europe/Rome')->year;
        $this->assertSame(sprintf('SAL-%d-0001', $anno), $validato['code']);
        $this->assertSame('validato', $validato['status']);
        $this->assertNotNull($validato['validated_at']);

        // Congelato: niente modifiche, niente righe tolte, niente eliminazione
        $this->patchJson("/api/v1/sals/{$sal['id']}", ['notes' => 'x'])->assertStatus(409);
        $this->deleteJson("/api/v1/sals/{$sal['id']}/items/{$sal['items'][0]['id']}")->assertStatus(409);
        $this->deleteJson("/api/v1/sals/{$sal['id']}")->assertStatus(409);

        // Il contatore non torna indietro nemmeno se piu' alto nelle impostazioni
        $this->organizzazione->refresh();
        $settings = $this->organizzazione->settings ?? [];
        $settings['sal_last_number'][(string) $anno] = 7;
        $this->organizzazione->forceFill(['settings' => $settings])->save();

        $this->ordineCompletato(['title' => 'Secondo']);
        $secondo = $this->creaSal();
        $codice = $this->postJson("/api/v1/sals/{$secondo['id']}/valida")->assertOk()->json('data.code');
        $this->assertSame(sprintf('SAL-%d-0008', $anno), $codice);
    }

    public function test_un_sal_rimasto_senza_righe_non_si_valida(): void
    {
        $this->ordineCompletato();
        $sal = $this->creaSal();
        $this->deleteJson("/api/v1/sals/{$sal['id']}/items/{$sal['items'][0]['id']}")->assertOk();

        $this->postJson("/api/v1/sals/{$sal['id']}/valida")->assertUnprocessable();
    }

    public function test_fatturato_solo_dopo_la_validazione(): void
    {
        $this->ordineCompletato();
        $sal = $this->creaSal();

        // In bozza non si segna
        $this->postJson("/api/v1/sals/{$sal['id']}/fatturato")->assertStatus(409);

        $this->postJson("/api/v1/sals/{$sal['id']}/valida")->assertOk();
        $segnato = $this->postJson("/api/v1/sals/{$sal['id']}/fatturato", ['invoice_ref' => 'FT 12/2026'])
            ->assertOk()->json('data');
        $this->assertSame('fatturato', $segnato['status']);
        $this->assertSame('FT 12/2026', $segnato['invoice_ref']);
        $this->assertNotNull($segnato['invoiced_at']);

        // Due volte no, e un fatturato resta immutabile
        $this->postJson("/api/v1/sals/{$sal['id']}/fatturato")->assertStatus(409);
        $this->patchJson("/api/v1/sals/{$sal['id']}", ['notes' => 'x'])->assertStatus(409);
    }

    public function test_la_stampa_dichiara_la_bozza_e_poi_usa_la_data_di_validazione(): void
    {
        $stampe = new RaccoglitorePdf;
        $this->app->instance(PdfRenderer::class, $stampe);
        $this->putJson('/api/v1/perizia/settings', ['luogo' => 'Mentana'])->assertOk();

        $this->ordineCompletato();
        $sal = $this->creaSal();

        $this->get("/api/v1/sals/{$sal['id']}/pdf")->assertOk();
        $html = $stampe->html['pdf.sal'];
        $this->assertStringContainsString('BOZZA — documento non validato', $html);
        $this->assertStringContainsString('stampato il', $html);
        $this->assertStringContainsString('500,00', $html);

        // Validato: la data del foglio e' quella della validazione, ovunque
        $this->postJson("/api/v1/sals/{$sal['id']}/valida")->assertOk();
        Sal::query()->whereKey($sal['id'])->update(['validated_at' => '2026-08-20 08:00:00']);

        $risposta = $this->get("/api/v1/sals/{$sal['id']}/pdf")->assertOk();
        $html = $stampe->html['pdf.sal'];
        $this->assertStringNotContainsString('BOZZA', $html);
        $this->assertStringContainsString('validato il 20/08/2026', $html);
        $this->assertStringContainsString('Mentana, 20/08/2026', $html);
        // Mai due date diverse sullo stesso foglio: quella di stampa non compare
        $this->assertStringNotContainsString(now('Europe/Rome')->format('d/m/Y'), $html);
        $this->assertStringContainsString('sal-2026', $risposta->headers->get('Content-Disposition'));
    }

    public function test_permessi_e_recinto_del_tenant(): void
    {
        $this->ordineCompletato();
        $sal = $this->creaSal();

        // L'operatore non gestisce i lavori: niente SAL
        $operatoreId = $this->postJson('/api/v1/users', [
            'name' => 'Operatore', 'email' => 'operatore-sal@example.com', 'role' => 'operatore',
        ])->assertCreated()->json('data.id');
        $this->actingAsTenantUser(\App\Models\User::withoutGlobalScopes()->findOrFail($operatoreId));
        $this->getJson('/api/v1/sals')->assertForbidden();

        // Un'altra organizzazione non vede questo SAL
        [, $estraneo] = $this->createTenantUser();
        $this->actingAsTenantUser($estraneo);
        $this->getJson('/api/v1/sals')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/sals/{$sal['id']}")->assertNotFound();
    }
}
