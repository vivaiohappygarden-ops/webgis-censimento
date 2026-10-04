<?php

namespace Tests\Feature;

use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\TestCase;

/**
 * Scadenze di concimazioni, trattamenti e altri prodotti (punto 11 del
 * committente, 04/10/2026): ogni intervento ha un tipo e la data del
 * prossimo; le scadenze escono in pagina e in Oggi finche' non si registra
 * l'intervento successivo; nel registro dei trattamenti fitosanitari entrano
 * solo i prodotti fitosanitari.
 */
class ScadenzeTrattamentiTest extends TestCase
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

    private function registra(array $dati): array
    {
        return $this->postJson('/api/v1/phyto-treatments', array_replace([
            'area_id' => $this->area->id, 'treated_on' => now('Europe/Rome')->subDays(30)->toDateString(),
            'product_name' => 'Prodotto', 'vegetation' => 'tappeto erboso', 'adversity' => 'generica',
            'method' => 'irrorazione', 'quantity' => 2, 'unit' => 'l',
        ], $dati))->assertCreated()->json('data');
    }

    public function test_una_concimazione_con_scadenza_entra_fra_le_scadenze_e_in_oggi_ma_non_nel_registro(): void
    {
        $concime = $this->registra(['kind' => 'concimazione', 'product_name' => 'Concime NPK 12-12-17', 'adversity' => 'carenza nutrizionale',
            'method' => 'altro', 'quantity' => 25, 'unit' => 'kg', 'treated_on' => now('Europe/Rome')->subDays(100)->toDateString(), 'next_due_on' => now('Europe/Rome')->subDays(10)->toDateString()]);
        $this->assertSame('concimazione', $concime['kind']);
        $fito = $this->registra(['product_name' => 'Insetticida X', 'adversity' => 'processionaria', 'next_due_on' => now('Europe/Rome')->addDays(20)->toDateString()]);
        $this->assertSame('fitosanitario', $fito['kind'], 'senza tipo e\' un trattamento fitosanitario, come prima');
        $this->registra(['product_name' => 'Senza scadenza']);

        $scadenze = $this->getJson('/api/v1/phyto-treatments/scadenze')->assertOk()->json('data');
        $this->assertSame(1, $scadenze['overdue_count']);
        $this->assertSame(1, $scadenze['due_soon_count']);
        $this->assertSame('Concimazione', $scadenze['rows'][0]['kind_label']);
        $this->assertSame('Concime NPK 12-12-17', $scadenze['rows'][0]['product_name']);

        // Filtro per tipo e lettura singola
        $this->assertCount(1, $this->getJson('/api/v1/phyto-treatments?kind=concimazione')->assertOk()->json('data'));
        $this->assertCount(3, $this->getJson('/api/v1/phyto-treatments')->assertOk()->json('data'));
        $this->assertSame('concimazione', $this->getJson('/api/v1/phyto-treatments/'.$concime['id'])->assertOk()->json('data.kind'));

        // Il registro dei trattamenti fitosanitari non stampa la concimazione
        $stampe = new RaccoglitorePdf;
        $this->app->instance(PdfRenderer::class, $stampe);
        $this->get('/api/v1/phyto-treatments/register-pdf?year='.now('Europe/Rome')->year)->assertOk();
        $nelRegistro = collect($stampe->dati['pdf.phyto-register']['treatments'])->pluck('product_name')->all();
        $this->assertContains('Insetticida X', $nelRegistro);
        $this->assertNotContains('Concime NPK 12-12-17', $nelRegistro);

        // Oggi: la concimazione scaduta e' in ritardo, il trattamento e' in scadenza
        $oggi = $this->getJson('/api/v1/oggi')->assertOk()->json('data');
        $voci = collect($oggi['voci'])->where('tipo', 'trattamento');
        $this->assertCount(2, $voci);
        $this->assertSame('ritardo', $voci->firstWhere('chiave', 'trattamento:'.$concime['id'])['urgenza']);
        $this->assertSame('presto', $voci->firstWhere('chiave', 'trattamento:'.$fito['id'])['urgenza']);
        $this->assertStringContainsString('Concimazione', $voci->firstWhere('chiave', 'trattamento:'.$concime['id'])['titolo']);
        $this->assertSame('/fitosanitari?ripeti='.$concime['id'], $voci->firstWhere('chiave', 'trattamento:'.$concime['id'])['azioni'][0]['href']);
        $this->assertSame(1, $oggi['conteggi']['trattamenti_scaduti']);
        $this->assertSame(1, $oggi['conteggi']['trattamenti_in_scadenza']);
        $this->assertSame(2, $oggi['conteggi']['famiglie']['lavori']);
        $this->assertSame(1, $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data.trattamenti.overdue_count'));
    }

    public function test_un_intervento_successivo_dello_stesso_tipo_chiude_la_scadenza(): void
    {
        $this->registra(['kind' => 'concimazione', 'method' => 'altro', 'unit' => 'kg', 'treated_on' => now('Europe/Rome')->subDays(100)->toDateString(), 'next_due_on' => now('Europe/Rome')->subDays(10)->toDateString()]);
        $this->assertSame(1, $this->getJson('/api/v1/phyto-treatments/scadenze')->json('data.overdue_count'));

        // Un trattamento fitosanitario sulla stessa area non chiude la concimazione
        $this->registra(['treated_on' => now('Europe/Rome')->subDays(3)->toDateString()]);
        $this->assertSame(1, $this->getJson('/api/v1/phyto-treatments/scadenze')->json('data.overdue_count'));

        // La concimazione successiva si': la scadenza era la sua
        $nuova = $this->registra(['kind' => 'concimazione', 'method' => 'altro', 'unit' => 'kg', 'treated_on' => now('Europe/Rome')->subDays(2)->toDateString(), 'next_due_on' => now('Europe/Rome')->addDays(90)->toDateString()]);
        $scadenze = $this->getJson('/api/v1/phyto-treatments/scadenze')->json('data');
        $this->assertSame(0, $scadenze['overdue_count']);
        $this->assertSame(0, $scadenze['due_soon_count'], 'fra 90 giorni: oltre la finestra di 60');
        $this->assertSame($nuova['id'], $this->getJson('/api/v1/phyto-treatments?kind=concimazione')->json('data.0.id'));
    }

    public function test_tipo_e_data_si_controllano(): void
    {
        $this->postJson('/api/v1/phyto-treatments', ['area_id' => $this->area->id, 'treated_on' => '2026-09-01', 'next_due_on' => '2026-08-01',
            'product_name' => 'X', 'vegetation' => 'v', 'adversity' => 'a', 'method' => 'irrorazione', 'quantity' => 1, 'unit' => 'l'])
            ->assertStatus(422)->assertJsonValidationErrors(['next_due_on']);
        $this->postJson('/api/v1/phyto-treatments', ['area_id' => $this->area->id, 'treated_on' => '2026-09-01', 'kind' => 'magia',
            'product_name' => 'X', 'vegetation' => 'v', 'adversity' => 'a', 'method' => 'irrorazione', 'quantity' => 1, 'unit' => 'l'])
            ->assertStatus(422)->assertJsonValidationErrors(['kind']);
        $this->getJson('/api/v1/phyto-treatments?kind=magia')->assertStatus(422);
    }
}
