<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inspection;
use App\Models\Organization;
use App\Models\TreeAssessment;
use App\Services\Pdf\LuogoFirma;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\TestCase;

/**
 * Luogo e data sopra la firma dei documenti stampati.
 *
 * Prima ogni stampa lasciava uno spazio da riempire a penna. Il luogo si
 * imposta una volta sola e vale per tutti i documenti; la data si scrive da
 * sé ed è quella di stampa, salvo per la perizia già validata, che è un atto
 * chiuso e ristampandola deve restare identica.
 */
class LuogoFirmaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organizzazione;

    private $utente;

    private RaccoglitorePdf $stampe;

    private $area = null;

    private $tipoAlbero = null;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->actingAsTenantUser($this->utente);

        $this->stampe = new RaccoglitorePdf;
        $this->app->instance(PdfRenderer::class, $this->stampe);
    }

    private function impostaLuogo(?string $luogo): void
    {
        $this->putJson('/api/v1/perizia/settings', ['luogo' => $luogo])->assertOk();
    }

    private function oggi(): string
    {
        return Carbon::now('Europe/Rome')->format('d/m/Y');
    }

    // --- La riga in sé -----------------------------------------------------

    public function test_senza_luogo_impostato_resta_la_sola_data(): void
    {
        $this->assertSame($this->oggi(), LuogoFirma::riga($this->organizzazione->id));
    }

    public function test_con_il_luogo_impostato_la_riga_e_luogo_e_data(): void
    {
        $this->impostaLuogo('Roma');

        $this->assertSame('Roma, '.$this->oggi(), LuogoFirma::riga($this->organizzazione->id));
    }

    public function test_una_data_indicata_vince_sulla_data_di_stampa(): void
    {
        $this->impostaLuogo('Roma');

        $this->assertSame(
            'Roma, 10/05/2026',
            LuogoFirma::riga($this->organizzazione->id, Carbon::parse('2026-05-10 08:00:00')),
        );
    }

    public function test_un_luogo_fatto_di_spazi_conta_come_non_impostato(): void
    {
        $this->impostaLuogo('   ');

        $this->assertNull(LuogoFirma::luogo($this->organizzazione->id));
        $this->assertSame($this->oggi(), LuogoFirma::riga($this->organizzazione->id));
    }

    public function test_il_luogo_si_puo_cambiare_e_togliere(): void
    {
        $this->impostaLuogo('Roma');
        $this->assertSame('Roma', $this->getJson('/api/v1/perizia/settings')->json('data.luogo'));

        $this->impostaLuogo('Guidonia Montecelio');
        $this->assertSame('Guidonia Montecelio', LuogoFirma::luogo($this->organizzazione->id));

        $this->impostaLuogo(null);
        $this->assertNull(LuogoFirma::luogo($this->organizzazione->id));
    }

    public function test_salvare_il_luogo_non_cancella_gli_altri_dati_del_professionista(): void
    {
        $this->putJson('/api/v1/perizia/settings', [
            'nome' => 'Mario Rossi', 'titolo' => 'Perito agrario',
        ])->assertOk();

        $this->impostaLuogo('Roma');

        $dati = $this->getJson('/api/v1/perizia/settings')->assertOk()->json('data');
        $this->assertSame('Mario Rossi', $dati['nome']);
        $this->assertSame('Perito agrario', $dati['titolo']);
        $this->assertSame('Roma', $dati['luogo']);
    }

    public function test_il_luogo_di_un_altra_impresa_non_finisce_nei_miei_documenti(): void
    {
        // Un luogo inconfondibile: "Roma" e' anche dentro cognomi e ragioni
        // sociali a caso ("Romano e figli"), e il controllo diventerebbe cieco
        $this->impostaLuogo('Roccacannuccia di Sopra');

        [$altra, $altroUtente] = $this->createTenantUser();
        $this->actingAsTenantUser($altroUtente);

        $this->assertNull(LuogoFirma::luogo($altra->id));
        $this->assertSame($this->oggi(), LuogoFirma::riga($altra->id));

        // E il controllo che conta: nel documento stampato dall'altra impresa
        $this->createArea($altra);
        $this->get('/api/v1/vta/bilancio/pdf?from=2026-01-01&to=2026-12-31')->assertOk();

        $html = $this->stampe->html['pdf.tree-balance'];
        $this->assertStringNotContainsString('Roccacannuccia', $html);
        $this->assertStringContainsString($this->oggi(), $html);
    }

    // --- Nei documenti -----------------------------------------------------

    public function test_il_bilancio_arboreo_porta_luogo_e_data_di_stampa(): void
    {
        $this->createArea($this->organizzazione);
        $this->impostaLuogo('Roma');

        $this->get('/api/v1/vta/bilancio/pdf?from=2026-01-01&to=2026-12-31')->assertOk();

        $this->assertStringContainsString('Roma, '.$this->oggi(), $this->stampe->html['pdf.tree-balance']);
        // Lo spazio da riempire a penna non c'è più
        $this->assertStringNotContainsString('Data ____ / ____', $this->stampe->html['pdf.tree-balance']);
    }

    public function test_il_verbale_di_ispezione_porta_luogo_e_data_di_stampa(): void
    {
        $area = $this->createArea($this->organizzazione);
        $this->impostaLuogo('Roma');

        $modello = $this->postJson('/api/v1/inspection-templates', [
            'name' => 'Controllo area verde', 'target' => 'area',
        ])->assertCreated()->json('data.id');
        $voci = $this->putJson("/api/v1/inspection-templates/{$modello}/items", [
            'items' => [['question' => 'Tappeto erboso in ordine']],
        ])->assertOk()->json('data.items');
        $ispezione = $this->postJson('/api/v1/inspections', [
            'template_id' => $modello,
            'area_id' => $area->id,
            'answers' => [$voci[0]['id'] => ['value' => 'ok']],
        ])->assertOk()->json('data.id');

        $this->get("/api/v1/inspections/{$ispezione}/pdf")->assertOk();

        $this->assertStringContainsString('Roma, '.$this->oggi(), $this->stampe->html['pdf.inspection']);
        $this->assertStringNotContainsString('Data: ____ / ____', $this->stampe->html['pdf.inspection']);

        // Il verbale e' dell'ispezione: ristampandolo un mese dopo la data
        // della firma resta quella del controllo, non quella della ristampa
        Inspection::withoutGlobalScopes()->where('id', $ispezione)
            ->update(['completed_at' => Carbon::parse('2026-03-04 09:30:00')]);
        $this->get("/api/v1/inspections/{$ispezione}/pdf")->assertOk();

        $this->assertStringContainsString('Roma, 04/03/2026', $this->stampe->html['pdf.inspection']);
        $this->assertStringNotContainsString('Roma, '.$this->oggi(), $this->stampe->html['pdf.inspection']);
    }

    public function test_il_registro_dei_fitosanitari_porta_luogo_e_data_di_stampa(): void
    {
        $area = $this->createArea($this->organizzazione);
        $this->impostaLuogo('Roma');

        $this->postJson('/api/v1/phyto-treatments', [
            'area_id' => $area->id,
            'treated_on' => Carbon::now('Europe/Rome')->toDateString(),
            'product_name' => 'Karate Zeon',
            'registration_number' => '12345',
            'active_substance' => 'lambda-cialotrina',
            'vegetation' => 'tigli in filare',
            'adversity' => 'afidi del tiglio',
            'method' => 'irrorazione',
            'quantity' => 1.5,
            'unit' => 'l',
        ])->assertCreated();

        $this->get('/api/v1/phyto-treatments/register-pdf?year='.Carbon::now('Europe/Rome')->year)->assertOk();

        $this->assertStringContainsString('Roma, '.$this->oggi(), $this->stampe->html['pdf.phyto-register']);
    }

    public function test_il_preventivo_non_porta_luogo_e_data(): void
    {
        // Decisione del committente: sul preventivo non serve. La data
        // dell'offerta e' gia' in testata, e sotto si firma per accettazione,
        // non per attestazione.
        $this->impostaLuogo('Roma');
        $committente = Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Condominio Test', 'client_type' => 'private',
        ]);
        $preventivo = $this->postJson('/api/v1/estimates', [
            'client_id' => $committente->id, 'title' => 'Potatura siepi',
        ])->assertCreated()->json('data.id');

        $this->get("/api/v1/estimates/{$preventivo}/pdf")->assertOk();

        // Il modello non riceve nemmeno il dato: non c'e' niente da stampare
        // (assertare l'assenza di "Roma" nel testo sarebbe fragile, il nome
        // dell'organizzazione nei test e' casuale e a volte contiene "Roma")
        $this->assertArrayNotHasKey('luogoData', $this->stampe->dati['pdf.estimate']);

        $html = $this->stampe->html['pdf.estimate'];
        $this->assertStringNotContainsString('Roma, '.$this->oggi(), $html);
        // Il resto del blocco della firma resta dov'era
        $this->assertStringContainsString('Per accettazione: data e firma del committente', $html);
    }

    public function test_la_prima_stampa_della_perizia_fissa_la_data_e_le_ristampe_non_la_cambiano(): void
    {
        $this->impostaLuogo('Roma');
        $perizia = $this->creaPerizia();

        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();
        $giornoDiEmissione = $this->oggi();

        $this->assertStringContainsString('Roma, '.$giornoDiEmissione, $this->stampe->html['pdf.perizia']);
        $this->assertStringNotContainsString('Luogo e data: ____', $this->stampe->html['pdf.perizia']);

        // Un mese dopo: stesso documento, stessa data
        $this->travel(30)->days();
        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();

        $this->assertStringContainsString('Roma, '.$giornoDiEmissione, $this->stampe->html['pdf.perizia']);
        $this->assertStringNotContainsString('Roma, '.$this->oggi(), $this->stampe->html['pdf.perizia']);
    }

    public function test_la_perizia_validata_non_cambia_data_quando_la_si_ristampa(): void
    {
        $this->impostaLuogo('Roma');
        $perizia = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$perizia}/valida")->assertOk();
        $giornoDiEmissione = $this->oggi();

        $this->travel(30)->days();
        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();

        $html = $this->stampe->html['pdf.perizia'];
        $this->assertStringContainsString('Roma, '.$giornoDiEmissione, $html);
        $this->assertStringNotContainsString('Roma, '.$this->oggi(), $html);
    }

    public function test_sulla_perizia_non_compaiono_due_date_diverse(): void
    {
        $this->impostaLuogo('Roma');
        $perizia = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$perizia}/valida")->assertOk();

        // La stampa avviene un mese dopo l'emissione: se la firma leggesse
        // l'orologio, testata e firma direbbero due giorni diversi
        $this->travel(30)->days();
        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();

        $emessaIl = TreeAssessment::withoutGlobalScopes()->findOrFail($perizia)
            ->report_issued_at->setTimezone('Europe/Rome')->format('d/m/Y');

        $html = $this->stampe->html['pdf.perizia'];
        $this->assertStringContainsString('Emessa il '.$emessaIl, $html);
        $this->assertStringContainsString('Roma, '.$emessaIl, $html);
    }

    public function test_salvare_il_luogo_non_azzera_il_contatore_dei_numeri_di_perizia(): void
    {
        // Il contatore dei protocolli vive nella stessa colonna "settings":
        // salvando le impostazioni su una copia letta prima si perderebbe
        $this->impostaLuogo('Roma');
        $prima = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$prima}/valida")->assertOk();
        $numeroPrima = TreeAssessment::withoutGlobalScopes()->findOrFail($prima)->report_number;

        $this->impostaLuogo('Guidonia Montecelio');

        $dopo = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$dopo}/valida")->assertOk();
        $numeroDopo = TreeAssessment::withoutGlobalScopes()->findOrFail($dopo)->report_number;

        $this->assertNotSame($numeroPrima, $numeroDopo);
        $this->assertSame(
            (int) substr($numeroPrima, -4) + 1,
            (int) substr($numeroDopo, -4),
        );
    }

    public function test_senza_luogo_impostato_i_documenti_mostrano_comunque_la_data(): void
    {
        $this->createArea($this->organizzazione);

        $this->get('/api/v1/vta/bilancio/pdf?from=2026-01-01&to=2026-12-31')->assertOk();

        $html = $this->stampe->html['pdf.tree-balance'];
        $this->assertStringContainsString($this->oggi(), $html);
        $this->assertStringNotContainsString('Data ____ / ____', $html);
    }

    private function creaPerizia(): string
    {
        // Tipo e area si creano una volta sola: ricrearli darebbe un codice
        // catalogo duplicato al secondo giro
        $area = $this->area ??= $this->createArea($this->organizzazione);
        $tipoAlbero = $this->tipoAlbero ??= $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $asset = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        return $this->postJson("/api/v1/assets/{$asset}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => '2026-05-10',
            'failure_class' => 'B',
            'outcome' => 'monitor',
        ])->assertCreated()->json('data.id');
    }
}
