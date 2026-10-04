<?php

namespace Tests\Feature;

use App\Models\CatalogObjectType;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\TestCase;

/**
 * La scheda stampata dell'elemento dal 04/10/2026 (punto 9 del committente:
 * "manca la mappa, la cronologia e altri dati"): posizione con coordinate e
 * planimetria disegnata dai dati censiti, tutte le valutazioni, lavori e
 * segnalazioni, benefici stimati, cronologia; ogni sezione si toglie con
 * ?sezioni=. Il modello Blade si compone davvero (RaccoglitorePdf).
 */
class SchedaPdfCompletaTest extends TestCase
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
    }

    private function tipo(string $codice, string $geo): CatalogObjectType
    {
        return CatalogObjectType::query()->where('code', $codice)->first() ?? $this->makeObjectType($this->organizzazione, $geo, $codice);
    }

    private function elemento(string $codice, array $geometria, string $tipo = 'P103108', string $geo = 'P'): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id, 'object_type_id' => $this->tipo($tipo, $geo)->id, 'census_code' => $codice, 'geometry' => $geometria,
        ])->assertCreated()->json('data.id');
    }

    public function test_la_scheda_stampa_posizione_planimetria_valutazioni_lavori_benefici_e_cronologia(): void
    {
        $albero = $this->elemento('ALB-0100', $this->pointGeometry(9.1905, 45.4652));
        $this->patchJson("/api/v1/assets/{$albero}", ['tree' => ['species' => 'Tilia cordata', 'height_m' => 14, 'dbh_cm' => 42, 'crown_diameter_m' => 9]])->assertOk();
        // Un vicino a pochi metri e una siepe: finiscono nella planimetria
        $this->elemento('ALB-0101', $this->pointGeometry(9.19062, 45.4652));
        $this->elemento('SIE-0001', ['type' => 'LineString', 'coordinates' => [[9.1903, 45.46505], [9.1907, 45.46505]]], 'P103201', 'L');

        $this->postJson("/api/v1/assets/{$albero}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => now('Europe/Rome')->subDays(3)->toDateString(), 'failure_class' => 'C',
            'assessor_external' => 'Giulia Verdi', 'prescriptions' => 'Rimonda del secco nella prossima stagione di riposo vegetativo',
        ])->assertCreated();
        $ordine = $this->postJson('/api/v1/work-orders', ['title' => 'Potatura di contenimento', 'asset_ids' => [$albero]])->assertCreated()->json('data');
        $this->postJson('/api/v1/issues', ['description' => 'Ramo spezzato sul marciapiede', 'asset_id' => $albero, 'category' => 'ramo', 'severity' => 'high'])->assertCreated();

        $this->get("/api/v1/assets/{$albero}/pdf")->assertOk();
        $dati = $this->stampe->dati['pdf.asset'];
        $html = $this->stampe->html['pdf.asset'];

        $this->assertSame(['posizione', 'dendro', 'vta', 'lavori', 'attributi', 'benefici', 'cronologia', 'foto'], $dati['sezioni']);

        // Posizione: coordinate geografiche e piane, planimetria PNG con vicini e area
        $posizione = $dati['posizione'];
        $this->assertEqualsWithDelta(45.4652, $posizione['lat'], 0.00001);
        $this->assertEqualsWithDelta(9.1905, $posizione['lon'], 0.00001);
        $this->assertSame(7791, $posizione['srid']);
        // RDN2008 / UTM 32N: Milano sta attorno a E 515.000, N 5.035.000
        $this->assertEqualsWithDelta(514900, $posizione['est'], 500, 'coordinate piane nel sistema metrico (EPSG:7791)');
        $this->assertEqualsWithDelta(5034700, $posizione['nord'], 500);
        $planimetria = $posizione['planimetria'];
        $this->assertNotNull($planimetria, 'la planimetria si disegna');
        $immagine = imagecreatefromstring($planimetria['png']);
        $this->assertNotFalse($immagine, 'la planimetria e\' un PNG leggibile');
        $this->assertSame([1200, 800], [imagesx($immagine), imagesy($immagine)]);
        $this->assertSame(2, $planimetria['vicini'], 'il vicino e la siepe entrano nella finestra');
        $this->assertSame(1, $planimetria['aree']);
        $this->assertTrue($planimetria['etichette']);
        $this->assertGreaterThanOrEqual(60, $planimetria['metri_altezza']);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('Planimetria schematica', $html);
        $this->assertStringContainsString('EPSG:7791', $html);
        $this->assertStringContainsString('Punto', $html);

        // Valutazioni: tutte, con rilevatore e prescrizioni
        $this->assertCount(1, $dati['valutazioni']);
        $this->assertStringContainsString('Valutazioni di stabilit', $html);
        $this->assertStringContainsString('Giulia Verdi', $html);
        $this->assertStringContainsString('Rimonda del secco', $html);

        // Lavori e segnalazioni
        $this->assertCount(1, $dati['lavori']['ordini']);
        $this->assertStringContainsString($ordine['code'], $html);
        $this->assertStringContainsString('Potatura di contenimento', $html);
        $this->assertCount(1, $dati['lavori']['segnalazioni']);
        $this->assertStringContainsString('Ramo spezzato', $html);

        // Benefici stimati e cronologia
        $this->assertNotNull($dati['benefici']['co2']);
        $this->assertStringContainsString('Benefici ambientali (stima)', $html);
        $titoli = array_column($dati['cronologia']['eventi'], 'titolo');
        $this->assertGreaterThanOrEqual(3, count($titoli), 'rilievo, valutazione, lavoro e segnalazione');
        $this->assertStringContainsString('Cronologia', $html);
        $this->assertStringContainsString('Stampata il', $html);
    }

    public function test_le_sezioni_si_scelgono_e_le_altre_non_si_calcolano(): void
    {
        $albero = $this->elemento('ALB-0200', $this->pointGeometry());
        $this->patchJson("/api/v1/assets/{$albero}", ['tree' => ['species' => 'Quercus ilex']])->assertOk();

        $this->get("/api/v1/assets/{$albero}/pdf?sezioni=dendro,foto")->assertOk();
        $dati = $this->stampe->dati['pdf.asset'];
        $this->assertSame(['dendro', 'foto'], $dati['sezioni']);
        $this->assertNull($dati['posizione']);
        $this->assertNull($dati['cronologia']);
        $this->assertNull($dati['benefici']);
        $this->assertTrue($dati['lavori']['ordini']->isEmpty());
        $html = $this->stampe->html['pdf.asset'];
        $this->assertStringNotContainsString('Planimetria', $html);
        $this->assertStringNotContainsString('Cronologia', $html);
        $this->assertStringContainsString('Quercus ilex', $html);
    }

    public function test_una_siepe_stampa_la_geometria_lineare_con_la_sua_lunghezza(): void
    {
        $siepe = $this->elemento('SIE-0100', ['type' => 'LineString', 'coordinates' => [[9.1903, 45.4650], [9.1909, 45.4650]]], 'P103201', 'L');

        $this->get("/api/v1/assets/{$siepe}/pdf?sezioni=posizione")->assertOk();
        $dati = $this->stampe->dati['pdf.asset'];
        $this->assertSame('LINESTRING', strtoupper((string) $dati['posizione']['tipo']));
        $this->assertNotNull($dati['posizione']['planimetria']);
        $html = $this->stampe->html['pdf.asset'];
        $this->assertStringContainsString('Linea', $html);
        $this->assertStringContainsString('lunghezza', $html);
        $this->assertStringNotContainsString('Valutazioni di stabilit', $html);
    }

    public function test_l_elemento_di_un_altra_organizzazione_non_si_stampa(): void
    {
        $albero = $this->elemento('ALB-0300', $this->pointGeometry());
        [, $altro] = $this->createTenantUser();
        $this->actingAsTenantUser($altro);
        $this->get("/api/v1/assets/{$albero}/pdf")->assertNotFound();
    }
}
