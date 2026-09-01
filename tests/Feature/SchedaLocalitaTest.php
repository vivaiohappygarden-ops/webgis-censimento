<?php

namespace Tests\Feature;

use App\Models\Locality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Scheda della localita': cosa c'e' dentro, quanto e' grande, chi ci lavora.
 */
class SchedaLocalitaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    private $tipoAlbero;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->tipoAlbero = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);
    }

    private function localita(): Locality
    {
        return Locality::findOrFail($this->area->locality_id);
    }

    private function creaAlbero(array $albero = []): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        if ($albero !== []) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $albero])->assertOk();
        }

        return $id;
    }

    public function test_la_scheda_riporta_superfici_e_conteggi(): void
    {
        $this->creaAlbero();

        $risposta = $this->getJson("/api/v1/localities/{$this->localita()->id}/scheda")->assertOk();

        $this->assertSame(1, $risposta->json('data.superfici.aree'));
        $this->assertGreaterThan(0, $risposta->json('data.superfici.totale_mq'));
        $this->assertSame('P103108', $risposta->json('data.per_tipo.0.code'));
        $this->assertSame(1, (int) $risposta->json('data.per_tipo.0.quanti'));
    }

    public function test_la_scheda_elenca_le_piante_con_nome_scientifico_e_quantita(): void
    {
        $this->creaAlbero(['species' => 'Tilia cordata', 'common_name' => 'Tiglio selvatico']);
        $this->creaAlbero(['species' => 'Tilia cordata', 'common_name' => 'Tiglio selvatico']);
        $this->creaAlbero(['species' => 'Acer campestre']);

        $piante = $this->getJson("/api/v1/localities/{$this->localita()->id}/scheda")
            ->assertOk()->json('data.piante');

        $this->assertSame('Tilia cordata', $piante[0]['scientifico']);
        $this->assertSame(2, (int) $piante[0]['quanti']);
        $this->assertSame('Tiglio selvatico', $piante[0]['comune']);
    }

    public function test_l_archivio_non_entra_nei_conteggi_della_scheda(): void
    {
        $this->creaAlbero(['species' => 'Tilia cordata']);
        $abbattuto = $this->creaAlbero(['species' => 'Tilia cordata']);
        $dismesso = $this->creaAlbero(['species' => 'Acer campestre']);
        $this->postJson("/api/v1/assets/{$abbattuto}/removal", ['removed_on' => '2026-08-14'])->assertOk();
        $this->patchJson("/api/v1/assets/{$dismesso}", ['status' => 'dismissed'])->assertOk();

        $dati = $this->getJson("/api/v1/localities/{$this->localita()->id}/scheda")
            ->assertOk()->json('data');

        // per_tipo contava perfino gli abbattuti; ora conta solo la gestione
        $this->assertSame(1, (int) $dati['per_tipo'][0]['quanti']);
        // e fra le piante il dismesso (che non ha removed_on) non compare
        $this->assertCount(1, $dati['piante']);
        $this->assertSame('Tilia cordata', $dati['piante'][0]['scientifico']);
        $this->assertSame(1, (int) $dati['piante'][0]['quanti']);
    }

    public function test_la_superficie_gestita_conta_solo_le_aree_attive(): void
    {
        $scheda = $this->getJson("/api/v1/localities/{$this->localita()->id}/scheda")->assertOk();
        $gestitaPrima = $scheda->json('data.superfici.gestita_mq');
        $this->assertGreaterThan(0, $gestitaPrima);

        $this->area->forceFill(['status' => 'dismissed'])->save();

        $dopo = $this->getJson("/api/v1/localities/{$this->localita()->id}/scheda")->assertOk();
        $this->assertSame(0.0, (float) $dopo->json('data.superfici.gestita_mq'));
        // La superficie totale invece non cambia: il terreno c'e' comunque
        $this->assertSame($scheda->json('data.superfici.totale_mq'), $dopo->json('data.superfici.totale_mq'));
    }

    public function test_la_classificazione_si_salva_e_torna_con_l_etichetta(): void
    {
        $id = $this->localita()->id;

        $this->patchJson("/api/v1/localities/{$id}", ['istat_class' => 'verde_attrezzato'])->assertOk();

        $this->getJson("/api/v1/localities/{$id}/scheda")
            ->assertOk()
            ->assertJsonPath('data.localita.istat_class', 'verde_attrezzato')
            ->assertJsonPath('data.localita.istat_class_label', 'Verde attrezzato');
    }

    public function test_una_classificazione_inventata_viene_rifiutata(): void
    {
        $this->patchJson("/api/v1/localities/{$this->localita()->id}", ['istat_class' => 'non_esiste'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('istat_class');
    }

    public function test_si_allega_e_si_toglie_un_documento(): void
    {
        $id = $this->localita()->id;

        $documento = $this->postJson("/api/v1/localities/{$id}/documenti", [
            'documento' => UploadedFile::fake()->create('piano-di-gestione.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');

        $this->getJson("/api/v1/localities/{$id}/scheda")
            ->assertOk()
            ->assertJsonPath('data.documenti.0.title', 'piano-di-gestione.pdf');

        $this->deleteJson("/api/v1/localities/{$id}/documenti/{$documento['id']}")->assertNoContent();

        $this->getJson("/api/v1/localities/{$id}/scheda")->assertOk()->assertJsonCount(0, 'data.documenti');
    }

    public function test_solo_i_pdf_si_allegano(): void
    {
        $this->postJson("/api/v1/localities/{$this->localita()->id}/documenti", [
            'documento' => UploadedFile::fake()->create('foglio.xlsx', 10),
        ])->assertStatus(422);
    }

    public function test_la_scheda_di_un_altra_impresa_non_si_apre(): void
    {
        [$altra] = $this->createTenantUser();
        $areaEstranea = $this->createArea($altra);

        $this->getJson("/api/v1/localities/{$areaEstranea->locality_id}/scheda")->assertNotFound();
        // Vale anche per la stampa: il dossier di un'altra impresa non esiste
        $this->getJson("/api/v1/localities/{$areaEstranea->locality_id}/pdf")->assertNotFound();
    }

    public function test_la_scheda_si_stampa_in_pdf_con_una_sola_data(): void
    {
        $stampe = new \Tests\Support\RaccoglitorePdf;
        $this->app->instance(\App\Services\Pdf\PdfRenderer::class, $stampe);

        $this->creaAlbero(['species' => 'Tilia cordata', 'common_name' => 'Tiglio selvatico']);

        // Un lavoro nella localita': deve comparire nel dossier con lo stato in italiano
        $woId = $this->postJson('/api/v1/work-orders', [
            'title' => 'Sfalcio del parco', 'area_id' => $this->area->id,
        ])->assertCreated()->json('data.id');

        $this->get("/api/v1/localities/{$this->localita()->id}/pdf", ['Accept' => 'application/pdf'])
            ->assertOk();

        $html = $stampe->html['pdf.locality'];
        $this->assertStringContainsString('Scheda della localit', $html);
        $this->assertStringContainsString('Tilia cordata', $html);
        $this->assertStringContainsString('Tiglio selvatico', $html);
        $this->assertStringContainsString('P103108', $html);
        $this->assertStringContainsString('Superficie gestita', $html);
        $this->assertStringContainsString('Sfalcio del parco', $html);
        $this->assertStringContainsString('Bozza', $html);
        // Il nome può contenere un apostrofo: nell'HTML esce come entità
        $this->assertStringContainsString(e($this->organizzazione->name), $html);

        // Una sola data sul foglio: quella di stampa, in nessun'altra forma
        $oggi = $stampe->dati['pdf.locality']['stampatoIl']->format('d/m/Y');
        $this->assertStringContainsString('stampato il '.$oggi, $html);
    }

    public function test_la_stampa_richiede_il_permesso_sulle_aree(): void
    {
        $localitaId = $this->localita()->id;

        // Stesso tenant, ma senza alcun ruolo: il dossier non si stampa
        $senzaRuoli = \App\Models\User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        $this->actingAsTenantUser($senzaRuoli);

        $this->get("/api/v1/localities/{$localitaId}/pdf", ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
