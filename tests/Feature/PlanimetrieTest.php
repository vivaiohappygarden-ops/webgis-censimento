<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Locality;
use App\Services\Pdf\PdfRenderer;
use App\Support\Geometry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\TestCase;

/**
 * Planimetrie delle aree nella scheda della località: quadro d'insieme e
 * tavola zoomata per area, con sfondo cartografico quando la rete c'è e
 * ripiego sul disegno tecnico quando manca.
 */
class PlanimetrieTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    private RaccoglitorePdf $stampe;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->actingAsTenantUser($this->utente);

        $this->stampe = new RaccoglitorePdf;
        $this->app->instance(PdfRenderer::class, $this->stampe);

        // Nei test niente rete di serie: il disegno tecnico basta. E la
        // scorta dei riquadri va in una cartella usa e getta: senza, un
        // riquadro finto salvato oggi farebbe passare il test di domani
        // senza piu' toccare Http::fake
        config([
            'planimetrie.sfondo' => 'disegno',
            'planimetrie.cache_dir' => storage_path('framework/testing/planimetrie-'.uniqid()),
        ]);
    }

    private function localita(): Locality
    {
        return Locality::findOrFail($this->area->locality_id);
    }

    private function creaElemento(string $geo, array $geometry, array $albero = []): string
    {
        // I tipi puntuali di questi test sono alberi veri (con la riga trees)
        $tipo = $this->makeObjectType($this->organizzazione, $geo, null,
            $geo === 'P' ? ['requires_tree_record' => true] : []);
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $tipo->id,
            'geometry' => $geometry,
        ])->assertCreated()->json('data.id');

        if ($albero !== []) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $albero])->assertOk();
        }

        return $id;
    }

    private function popolaArea(): void
    {
        $this->creaElemento('P', $this->pointGeometry(9.1900, 45.4650), ['crown_diameter_m' => 8]);
        $this->creaElemento('P', $this->pointGeometry(9.1912, 45.4655));   // albero senza chioma? no: senza scheda albero
        $this->creaElemento('S', ['type' => 'Polygon', 'coordinates' => [[
            [9.1895, 45.4645], [9.1905, 45.4645], [9.1905, 45.4650], [9.1895, 45.4650], [9.1895, 45.4645],
        ]]]);
        $this->creaElemento('L', ['type' => 'LineString', 'coordinates' => [
            [9.1915, 45.4642], [9.1925, 45.4648], [9.1927, 45.4655],
        ]]);
    }

    public function test_la_stampa_porta_le_planimetrie_con_didascalie_e_conteggi(): void
    {
        $this->popolaArea();

        $this->get("/api/v1/localities/{$this->localita()->id}/pdf")->assertOk();
        $html = $this->stampe->html['pdf.locality'];

        $this->assertStringContainsString('Planimetrie delle aree', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('Area 1 — Area Test', $html);
        $this->assertStringContainsString('4 elementi', $html);
        $this->assertStringContainsString('1 superficie', $html);
        $this->assertStringContainsString('1 elemento lineare', $html);
        // Senza rete niente attribuzione dello sfondo, e la nota lo dichiara
        $this->assertStringNotContainsString('OpenStreetMap', $html);
        $this->assertStringContainsString('lo sfondo cartografico si omette', $html);

        // L'immagine incorporata e' un PNG vero
        preg_match('/data:image\/png;base64,([A-Za-z0-9+\/=]+)/', $html, $m);
        $this->assertSame("\x89PNG", substr(base64_decode($m[1]), 0, 4));
    }

    public function test_la_sezione_si_esclude_dalla_stampa_componibile(): void
    {
        $this->popolaArea();

        $this->get("/api/v1/localities/{$this->localita()->id}/pdf?sezioni=tipi")->assertOk();
        $html = $this->stampe->html['pdf.locality'];

        $this->assertStringNotContainsString('Planimetrie delle aree', $html);
        $this->assertStringNotContainsString('data:image/png', $html);
    }

    public function test_il_quadro_d_insieme_compare_dalla_seconda_area(): void
    {
        $this->get("/api/v1/localities/{$this->localita()->id}/pdf")->assertOk();
        $this->assertStringNotContainsString("Quadro d'insieme",
            $this->stampe->html['pdf.locality']);

        Area::create([
            'tenant_id' => $this->organizzazione->id,
            'locality_id' => $this->area->locality_id,
            'name' => 'Area Due',
            'geom' => Geometry::toEwkb(['type' => 'Polygon', 'coordinates' => [[
                [9.1935, 45.4640], [9.1950, 45.4640], [9.1950, 45.4652], [9.1935, 45.4652], [9.1935, 45.4640],
            ]]], forceMultiPolygon: true),
        ]);

        $this->get("/api/v1/localities/{$this->localita()->id}/pdf")->assertOk();
        $html = $this->stampe->html['pdf.locality'];
        $this->assertStringContainsString("Quadro d'insieme", $html);
        // Le aree sono in ordine di nome: "Area Due" precede "Area Test"
        $this->assertStringContainsString('Area 1 — Area Due', $html);
        $this->assertStringContainsString('Area 2 — Area Test', $html);
    }

    public function test_un_elemento_abbattuto_non_entra_nei_conteggi_della_tavola(): void
    {
        $this->creaElemento('P', $this->pointGeometry(9.1900, 45.4650), ['crown_diameter_m' => 8]);
        $altro = $this->creaElemento('P', $this->pointGeometry(9.1912, 45.4655));
        DB::table('assets')->where('id', $altro)->update(['status' => 'removed']);

        $this->get("/api/v1/localities/{$this->localita()->id}/pdf")->assertOk();
        $this->assertStringContainsString('1 elemento (1 albero)', $this->stampe->html['pdf.locality']);
    }

    public function test_con_la_rete_lo_sfondo_entra_e_l_attribuzione_pure(): void
    {
        config([
            'planimetrie.sfondo' => 'auto',
            'planimetrie.tile_url' => 'https://riquadri-di-prova.example/{z}/{x}/{y}.png',
        ]);
        Http::fake(['riquadri-di-prova.example/*' => Http::response($this->riquadroFinto(), 200)]);

        $this->popolaArea();
        $this->get("/api/v1/localities/{$this->localita()->id}/pdf")->assertOk();
        $html = $this->stampe->html['pdf.locality'];

        $this->assertStringContainsString('OpenStreetMap', $html);
        $this->assertStringContainsString('Planimetrie delle aree', $html);
    }

    public function test_senza_rete_si_ripiega_sul_disegno_tecnico_senza_errori(): void
    {
        config([
            'planimetrie.sfondo' => 'auto',
            'planimetrie.tile_url' => 'https://riquadri-spenti.example/{z}/{x}/{y}.png',
        ]);
        Http::fake(['riquadri-spenti.example/*' => Http::response('', 503)]);

        $this->popolaArea();
        $this->get("/api/v1/localities/{$this->localita()->id}/pdf")->assertOk();
        $html = $this->stampe->html['pdf.locality'];

        $this->assertStringContainsString('Planimetrie delle aree', $html);
        $this->assertStringNotContainsString('OpenStreetMap', $html);
    }

    public function test_un_multipoint_dagli_import_si_disegna_e_si_conta(): void
    {
        // L'import di uno shapefile puo' produrre MultiPoint a piu' parti:
        // la tavola deve disegnarli, non solo contarli
        $this->creaElemento('P', ['type' => 'MultiPoint', 'coordinates' => [
            [9.1900, 45.4650], [9.1910, 45.4653],
        ]], ['crown_diameter_m' => 6]);

        $this->get("/api/v1/localities/{$this->localita()->id}/pdf")->assertOk();
        $html = $this->stampe->html['pdf.locality'];
        $this->assertStringContainsString('1 elemento (1 albero)', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_oltre_il_tetto_le_tavole_si_omettono_e_la_stampa_lo_dichiara(): void
    {
        config(['planimetrie.massimo_tavole' => 1]);
        Area::create([
            'tenant_id' => $this->organizzazione->id,
            'locality_id' => $this->area->locality_id,
            'name' => 'Area Due',
            'geom' => Geometry::toEwkb($this->squarePolygon(), forceMultiPolygon: true),
        ]);

        $this->get("/api/v1/localities/{$this->localita()->id}/pdf")->assertOk();
        $html = $this->stampe->html['pdf.locality'];
        $this->assertStringContainsString('1 area resta', $html);
        $this->assertStringContainsString('senza planimetria', $html);
    }

    public function test_al_primo_intoppo_lo_sfondo_si_spegne_per_tutta_la_stampa(): void
    {
        config([
            'planimetrie.sfondo' => 'auto',
            'planimetrie.tile_url' => 'https://riquadri-morti.example/{z}/{x}/{y}.png',
        ]);
        Http::fake(['riquadri-morti.example/*' => Http::response('', 503)]);

        Area::create([
            'tenant_id' => $this->organizzazione->id,
            'locality_id' => $this->area->locality_id,
            'name' => 'Area Due',
            'geom' => Geometry::toEwkb($this->squarePolygon(), forceMultiPolygon: true),
        ]);

        // Tre tavole (quadro + due aree), ma UNA sola richiesta in rete:
        // dopo il primo fallimento non si aspetta piu' niente
        $this->get("/api/v1/localities/{$this->localita()->id}/pdf")->assertOk();
        $this->assertCount(1, Http::recorded());
    }

    public function test_un_elemento_con_coordinate_lontane_non_stravolge_la_tavola(): void
    {
        // Caso visto in produzione: un punto creato con la mappa su
        // un'altra citta'. La tavola resta zoomata sull'area e la
        // didascalia dichiara l'elemento fuori inquadratura
        $this->creaElemento('P', $this->pointGeometry(9.1900, 45.4650), ['crown_diameter_m' => 8]);
        $this->creaElemento('P', $this->pointGeometry(12.4964, 41.9028));   // Roma, area a Milano

        $this->get("/api/v1/localities/{$this->localita()->id}/pdf")->assertOk();
        $html = $this->stampe->html['pdf.locality'];

        $this->assertStringContainsString('2 elementi (2 alberi)', $html);
        $this->assertStringContainsString('1 elemento con coordinate lontane', $html);
        $this->assertStringContainsString("fuori dall'inquadratura: da verificare nel censimento", $html);
    }

    public function test_il_disegno_e_riproducibile_a_parita_di_dati(): void
    {
        $this->popolaArea();

        $prima = app(\App\Services\Pdf\Planimetria::class)->perLocalita($this->localita());
        $seconda = app(\App\Services\Pdf\Planimetria::class)->perLocalita($this->localita());

        $this->assertSame($prima['aree'][0]['png'], $seconda['aree'][0]['png']);
        $this->assertFalse($prima['sfondo_usato']);
    }

    /** Un PNG 256x256 qualsiasi, buono da riquadro finto. */
    private function riquadroFinto(): string
    {
        $im = imagecreatetruecolor(256, 256);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 230, 228, 220));
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
