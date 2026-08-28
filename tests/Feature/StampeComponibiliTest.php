<?php

namespace Tests\Feature;

use App\Models\Locality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Stampe componibili: con ?sezioni=a,b la scheda PDF esce con le sole
 * sezioni chieste; senza parametro esce tutta, come sempre. Testata e dati
 * generali non si tolgono mai.
 */
class StampeComponibiliTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    private $tipoAlbero;

    private \Tests\Support\RaccoglitorePdf $stampe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->tipoAlbero = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);

        $this->stampe = new \Tests\Support\RaccoglitorePdf;
        $this->app->instance(\App\Services\Pdf\PdfRenderer::class, $this->stampe);
    }

    private function alberoCompleto(): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'census_code' => 'ALB-SEZ-1',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Tilia', 'species' => 'Tilia cordata', 'dbh_cm' => 30],
        ])->assertOk();

        $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => '2026-08-01', 'failure_class' => 'B',
        ])->assertCreated();

        $this->post("/api/v1/assets/{$id}/photos", [
            'photo' => UploadedFile::fake()->image('albero.jpg', 320, 240),
            'category' => 'census',
        ])->assertCreated();

        return $id;
    }

    public function test_senza_parametro_la_scheda_esce_tutta(): void
    {
        $id = $this->alberoCompleto();
        $this->get("/api/v1/assets/{$id}/pdf")->assertOk();

        $html = $this->stampe->html['pdf.asset'];
        $this->assertStringContainsString('Dati dendrometrici e agronomici', $html);
        $this->assertStringContainsString('Ultima valutazione di stabilità', $html);
        $this->assertStringContainsString('Documentazione fotografica', $html);
    }

    public function test_con_le_sezioni_scelte_escono_solo_quelle(): void
    {
        $id = $this->alberoCompleto();
        $this->get("/api/v1/assets/{$id}/pdf?sezioni=dendro")->assertOk();

        $html = $this->stampe->html['pdf.asset'];
        // La testata resta sempre
        $this->assertStringContainsString('ALB-SEZ-1', $html);
        $this->assertStringContainsString('Dati dendrometrici e agronomici', $html);
        $this->assertStringNotContainsString('Ultima valutazione di stabilità', $html);
        $this->assertStringNotContainsString('Documentazione fotografica', $html);
    }

    public function test_le_voci_sconosciute_si_ignorano_e_il_vuoto_lascia_la_testata(): void
    {
        $id = $this->alberoCompleto();

        // Voce inventata: si ignora, resta la sezione valida
        $this->get("/api/v1/assets/{$id}/pdf?sezioni=marziana,foto")->assertOk();
        $html = $this->stampe->html['pdf.asset'];
        $this->assertStringContainsString('Documentazione fotografica', $html);
        $this->assertStringNotContainsString('Dati dendrometrici', $html);

        // Elenco esplicitamente vuoto: solo testata e dati generali
        $this->get("/api/v1/assets/{$id}/pdf?sezioni=")->assertOk();
        $html = $this->stampe->html['pdf.asset'];
        $this->assertStringContainsString('ALB-SEZ-1', $html);
        $this->assertStringNotContainsString('Dati dendrometrici', $html);
        $this->assertStringNotContainsString('Documentazione fotografica', $html);
    }

    public function test_la_forma_a_elenco_del_parametro_non_rompe_la_stampa(): void
    {
        $id = $this->alberoCompleto();

        // ?sezioni[]=foto: forma insolita ma legittima, non deve dare errore
        $this->get("/api/v1/assets/{$id}/pdf?sezioni[]=foto")->assertOk();
        $html = $this->stampe->html['pdf.asset'];
        $this->assertStringContainsString('Documentazione fotografica', $html);
        $this->assertStringNotContainsString('Dati dendrometrici', $html);
    }

    public function test_anche_la_scheda_della_localita_e_componibile(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['species' => 'Tilia cordata'],
        ])->assertOk();
        $this->postJson('/api/v1/work-orders', [
            'title' => 'Sfalcio di prova', 'area_id' => $this->area->id,
        ])->assertCreated();

        $localitaId = Locality::findOrFail($this->area->locality_id)->id;

        $this->get("/api/v1/localities/{$localitaId}/pdf?sezioni=piante")->assertOk();
        $html = $this->stampe->html['pdf.locality'];
        // Superfici e testata sempre; le altre sezioni solo se scelte
        $this->assertStringContainsString('Superficie gestita', $html);
        $this->assertStringContainsString('Piante presenti', $html);
        $this->assertStringContainsString('Tilia cordata', $html);
        $this->assertStringNotContainsString('Lavori recenti', $html);
        $this->assertStringNotContainsString('Sfalcio di prova', $html);
        $this->assertStringNotContainsString('Documenti allegati', $html);

        // Senza parametro esce tutta, come prima
        $this->get("/api/v1/localities/{$localitaId}/pdf")->assertOk();
        $html = $this->stampe->html['pdf.locality'];
        $this->assertStringContainsString('Lavori recenti', $html);
        $this->assertStringContainsString('Sfalcio di prova', $html);
    }
}
