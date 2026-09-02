<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\WorkLog;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\TestCase;

/**
 * Relazione annuale del verde: il PDF per committente e anno da consegnare
 * all'ente a fine anno.
 *
 * Le regole che contano: ogni sezione senza dati non si stampa (la relazione
 * di un committente appena preso non deve essere una fila di zeri), le schede
 * dismesse restano fuori dal patrimonio, gli abbattuti dell'anno entrano nel
 * bilancio con la loro data, e sul foglio c'e' una sola data di stampa.
 */
class RelazioneAnnualeTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organizzazione;

    private $utente;

    private $area;

    private $tipoAlbero;

    private RaccoglitorePdf $stampe;

    private int $anno;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->tipoAlbero = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);

        $this->stampe = new RaccoglitorePdf;
        $this->app->instance(PdfRenderer::class, $this->stampe);

        // I dati creati via API nascono "oggi": la relazione si chiede
        // sull'anno corrente, cosi' i conteggi li trovano
        $this->anno = Carbon::now('Europe/Rome')->year;
    }

    private function committente(): Client
    {
        return Client::withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)->firstOrFail();
    }

    /** @return string id dell'elemento */
    private function creaAlbero(string $census, array $tree = []): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'census_code' => $census,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        if ($tree !== []) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $tree])->assertOk();
        }

        return $id;
    }

    private function ordineCompletato(Client $client, array $logs = []): WorkOrder
    {
        $tipo = WorkType::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->organizzazione->id, 'code' => 'POT'],
            ['name' => 'Potatura', 'unit' => 'cad'],
        );
        $ordine = WorkOrder::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => 'ODL-REL-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'Lavoro dell\'anno',
            'status' => 'completed',
            'client_id' => $client->id,
            'work_type_id' => $tipo->id,
        ]);
        // completed_at nell'anno in corso, aggiornato senza passare dal
        // modello per non muovere lo stato
        WorkOrder::query()->whereKey($ordine->id)->update(['completed_at' => now()]);
        foreach ($logs as $log) {
            WorkLog::create([
                'tenant_id' => $this->organizzazione->id,
                'work_order_id' => $ordine->id,
                'operator_id' => $this->utente->id,
                'started_at' => now(),
                ...$log,
            ]);
        }

        return $ordine;
    }

    /** Stampa la relazione e restituisce l'HTML composto. */
    private function stampa(Client $client, ?int $anno = null): string
    {
        $anno ??= $this->anno;
        $this->get("/api/v1/reports/relazione-annuale/pdf?client_id={$client->id}&anno={$anno}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        return $this->stampe->html['pdf.relazione-annuale'];
    }

    private function dati(): array
    {
        return $this->stampe->dati['pdf.relazione-annuale']['relazione'];
    }

    public function test_un_anno_con_dati_porta_bilancio_lavori_vta_e_co2_nel_testo(): void
    {
        $client = $this->committente();

        // Un albero con diametro (per la CO2) e uno abbattuto quest'anno
        $vivo = $this->creaAlbero('REL-A', ['species' => 'Tilia cordata', 'dbh_cm' => 40]);
        $this->creaAlbero('REL-B', [
            'species' => 'Pinus pinea',
            'planted_on' => ($this->anno - 3).'-04-01',
            'removed_on' => $this->anno.'-06-15',
        ]);

        $this->ordineCompletato($client, [['man_hours' => 5, 'quantity' => 3, 'unit' => 'cad']]);

        $this->postJson("/api/v1/assets/{$vivo}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => $this->anno.'-05-10',
            'failure_class' => 'B',
            'outcome' => 'prescriptions',
            'prescriptions' => 'Rimonda del secco',
        ])->assertCreated();

        Issue::create([
            'tenant_id' => $this->organizzazione->id,
            'client_id' => $client->id,
            'description' => 'Ramo spezzato sul viale',
        ]);

        $this->postJson('/api/v1/phyto-treatments', [
            'area_id' => $this->area->id,
            'treated_on' => Carbon::now('Europe/Rome')->toDateString(),
            'product_name' => 'Karate Zeon',
            'vegetation' => 'tigli in filare',
            'adversity' => 'afidi',
            'method' => 'irrorazione',
            'quantity' => 1.5,
            'unit' => 'l',
        ])->assertCreated();

        $html = $this->stampa($client);

        $this->assertStringContainsString('Relazione annuale del verde '.$this->anno, $html);
        $this->assertStringContainsString($client->name, $html);
        $this->assertStringContainsString('Bilancio arboreo dell\'anno', $html);
        // L'abbattuto dell'anno compare fra le specie toccate
        $this->assertStringContainsString('Pinus pinea', $html);
        $this->assertStringContainsString('I lavori dell\'anno', $html);
        $this->assertStringContainsString('Potatura', $html);
        $this->assertStringContainsString('Valutazioni di stabilita', $html);
        $this->assertStringContainsString('Le segnalazioni dell\'anno', $html);
        $this->assertStringContainsString('I trattamenti fitosanitari dell\'anno', $html);
        $this->assertStringContainsString('Karate Zeon', $html);
        $this->assertStringContainsString('Anidride carbonica', $html);
        // Il metodo della stima e' dichiarato per esteso, come sul portale
        $this->assertStringContainsString(config('co2.modello'), $html);
    }

    public function test_un_anno_senza_dati_stampa_il_patrimonio_e_salta_le_sezioni_vuote(): void
    {
        // Il censimento esiste (oggi), ma la relazione chiesta e' di un anno
        // lontano: nessun lavoro, controllo o movimento da raccontare
        $this->creaAlbero('REL-VUOTO', ['species' => 'Tilia cordata']);

        $html = $this->stampa($this->committente(), 2020);

        $this->assertStringContainsString('Il patrimonio in gestione', $html);
        $this->assertStringNotContainsString('Bilancio arboreo dell\'anno', $html);
        $this->assertStringNotContainsString('I lavori dell\'anno', $html);
        $this->assertStringNotContainsString('I controlli dell\'anno', $html);
        $this->assertStringNotContainsString('Le segnalazioni dell\'anno', $html);
        $this->assertStringNotContainsString('I trattamenti fitosanitari dell\'anno', $html);
        // Senza diametri niente stima CO2: meglio nessun dato che un dato finto
        $this->assertStringNotContainsString('Anidride carbonica', $html);
        $this->assertStringNotContainsString('Documentazione fotografica', $html);
        // La firma c'e' comunque: e' un documento del professionista
        $this->assertStringContainsString('timbro e firma', $html);
    }

    public function test_il_committente_di_un_altra_impresa_e_rifiutato(): void
    {
        [$altra] = $this->createTenantUser();
        $this->createArea($altra);
        $estraneo = Client::withoutGlobalScopes()->where('tenant_id', $altra->id)->firstOrFail();

        $this->get("/api/v1/reports/relazione-annuale/pdf?client_id={$estraneo->id}&anno={$this->anno}")
            ->assertNotFound();
        $this->getJson("/api/v1/reports/relazione-annuale/primo-anno?client_id={$estraneo->id}")
            ->assertNotFound();
    }

    public function test_le_schede_dismesse_non_contano_nel_patrimonio(): void
    {
        $this->creaAlbero('REL-1');
        $this->creaAlbero('REL-2');
        $dismesso = $this->creaAlbero('REL-3');
        Asset::withoutGlobalScopes()->whereKey($dismesso)->update(['status' => 'dismissed']);

        $this->stampa($this->committente());

        $patrimonio = $this->dati()['patrimonio'];
        $this->assertSame(2, $patrimonio['elementi']);
        $this->assertSame(2, $patrimonio['alberi']);
    }

    public function test_gli_abbattuti_dell_anno_entrano_nel_bilancio_con_la_loro_data(): void
    {
        // Abbattuto quest'anno: nel bilancio dell'anno
        $this->creaAlbero('REL-FELL', [
            'planted_on' => ($this->anno - 5).'-03-01',
            'removed_on' => $this->anno.'-02-20',
        ]);
        // Abbattuto l'anno prima: fuori dal bilancio di quest'anno
        $this->creaAlbero('REL-FELL-VECCHIO', [
            'planted_on' => ($this->anno - 5).'-03-01',
            'removed_on' => ($this->anno - 1).'-08-10',
        ]);

        $this->stampa($this->committente());

        $bilancio = $this->dati()['bilancio'];
        $this->assertSame(1, $bilancio['felled_count']);
        $this->assertSame(1, $bilancio['initial_count']);
    }

    public function test_le_fotografie_oltre_il_tetto_si_fermano_a_otto_e_il_totale_e_dichiarato(): void
    {
        $elemento = $this->creaAlbero('REL-FOTO');
        for ($i = 1; $i <= 10; $i++) {
            $this->post("/api/v1/assets/{$elemento}/photos", [
                'photo' => UploadedFile::fake()->image("foto-{$i}.jpg", 400, 300),
                'category' => 'after',
                'taken_at' => $this->anno.'-03-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).' 10:00:00',
            ])->assertCreated();
        }

        $html = $this->stampa($this->committente());

        $foto = $this->dati()['foto'];
        $this->assertCount(8, $foto['foto']);
        $this->assertSame(10, $foto['totale']);
        $this->assertStringContainsString('Documentazione fotografica', $html);
        $this->assertStringContainsString('se ne stampano 8', $html);
    }

    public function test_sul_foglio_compare_un_solo_stampato_il(): void
    {
        $this->creaAlbero('REL-DATA');

        $html = $this->stampa($this->committente());

        // Una sola lettura dell'orologio, una sola data sul foglio
        $this->assertSame(1, substr_count($html, 'stampato il'));
    }

    public function test_l_anno_futuro_e_quello_assurdo_sono_rifiutati(): void
    {
        $client = $this->committente();

        $this->getJson("/api/v1/reports/relazione-annuale/pdf?client_id={$client->id}&anno=".($this->anno + 1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('anno');
        $this->getJson("/api/v1/reports/relazione-annuale/pdf?client_id={$client->id}&anno=1999")
            ->assertStatus(422)
            ->assertJsonValidationErrors('anno');
    }

    public function test_senza_il_permesso_dei_lavori_non_si_scarica(): void
    {
        $client = $this->committente();
        [, $senzaPermessi] = $this->createTenantUser(role: 'cliente');
        $this->actingAsTenantUser($senzaPermessi);

        $this->get("/api/v1/reports/relazione-annuale/pdf?client_id={$client->id}&anno={$this->anno}")
            ->assertForbidden();
    }

    public function test_il_primo_anno_con_dati_parte_dal_primo_censimento(): void
    {
        $client = $this->committente();
        $vecchio = $this->creaAlbero('REL-VECCHIO');
        Asset::withoutGlobalScopes()->whereKey($vecchio)
            ->update(['created_at' => Carbon::create(2022, 3, 1, 12, 0, 0, 'Europe/Rome')]);

        $this->getJson("/api/v1/reports/relazione-annuale/primo-anno?client_id={$client->id}")
            ->assertOk()
            ->assertJsonPath('data.primo_anno', 2022);
    }

    public function test_il_nome_del_file_porta_committente_e_anno(): void
    {
        $client = $this->committente();
        $client->update(['code' => 'COM-01']);

        $this->get("/api/v1/reports/relazione-annuale/pdf?client_id={$client->id}&anno={$this->anno}")
            ->assertOk()
            ->assertHeader('Content-Disposition',
                'attachment; filename="relazione-verde-com-01-'.$this->anno.'.pdf"');
    }
}
