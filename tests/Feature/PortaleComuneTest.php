<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Locality;
use App\Models\Site;
use App\Models\TreeAssessment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Il portale del Comune (dal 26/09/2026): l'ufficio tecnico vede mappa,
 * elenco e schede dei suoi elementi, i lavori, i documenti emessi e le
 * fotografie del proprio territorio, col solo permesso portal.view. Mai un
 * elemento, un'area o un documento di un altro committente; mai un prezzo.
 */
class PortaleComuneTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $amministratore;

    private $tipoAlbero;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
        Storage::fake('local');
        Http::fake(['tile.openstreetmap.org/*' => Http::response('', 500)]);

        [$this->organizzazione, $this->amministratore] = $this->createTenantUser();
        $this->tipoAlbero = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
    }

    /** Committente con sede, localita', area e l'utente del portale collegato. */
    private function comune(string $nome): array
    {
        $cliente = Client::create(['tenant_id' => $this->organizzazione->id, 'name' => "Comune di {$nome}", 'client_type' => 'public']);
        $sede = Site::create(['tenant_id' => $this->organizzazione->id, 'client_id' => $cliente->id, 'name' => "Sede {$nome}"]);
        $localita = Locality::create(['tenant_id' => $this->organizzazione->id, 'site_id' => $sede->id, 'name' => "Centro {$nome}"]);
        $area = $this->createArea($this->organizzazione, ['locality_id' => $localita->id, 'name' => "Parco {$nome}"]);

        $utente = User::factory()->create(['tenant_id' => $this->organizzazione->id, 'client_id' => $cliente->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $utente->assignRole('cliente');

        return [$cliente, $area, $utente];
    }

    /** Un albero censito dall'amministratore, con specie e misure. */
    private function albero(string $areaId, string $cartellino, float $lon = 9.1905, float $lat = 45.4652): string
    {
        $this->actingAsTenantUser($this->amministratore);
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $areaId,
            'object_type_id' => $this->tipoAlbero->id,
            'census_code' => $cartellino,
            'geometry' => $this->pointGeometry($lon, $lat),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Tilia', 'species' => 'Tilia cordata', 'common_name' => 'Tiglio', 'height_m' => 12, 'dbh_cm' => 40],
        ])->assertOk();

        return $id;
    }

    private function valutazione(string $assetId, array $extra = []): array
    {
        $this->actingAsTenantUser($this->amministratore);

        return $this->postJson("/api/v1/assets/{$assetId}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now('Europe/Rome')->toDateString(),
            'failure_class' => 'C',
            'outcome' => 'prescriptions',
            'prescriptions' => 'Potatura di alleggerimento della chioma.',
            ...$extra,
        ])->assertCreated()->json('data');
    }

    /** Il riquadro (z, x, y) che contiene un punto. */
    private function riquadro(float $lon, float $lat, int $z = 17): array
    {
        $n = 2 ** $z;
        $x = (int) floor(($lon + 180) / 360 * $n);
        $latRad = deg2rad($lat);
        $y = (int) floor((1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n);

        return [$z, $x, $y];
    }

    public function test_la_pagina_nuova_si_apre_con_gli_sfondi_e_quella_di_prima_resta(): void
    {
        [, , $utente] = $this->comune('Guidonia');
        $this->actingAsTenantUser($utente);

        $this->get('/portale')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Nuovo/Portale')
            ->has('sfondi')
            ->has('navigazioneUrl'));
        $this->get('/portale?precedente=1')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Portale'));

        // Chi non ha il permesso del portale non entra, ne' dalla pagina ne' dai dati
        $this->actingAsTenantUser(User::factory()->create(['tenant_id' => $this->organizzazione->id]));
        $this->get('/portale')->assertForbidden();
        $this->getJson('/api/v1/portal/elementi')->assertForbidden();
        $this->getJson('/api/v1/portal/lavori')->assertForbidden();
        $this->getJson('/api/v1/portal/documenti')->assertForbidden();
        $this->get('/api/v1/portal/tiles/17/1/1')->assertForbidden();
    }

    public function test_la_mappa_porta_solo_aree_ed_elementi_del_proprio_territorio(): void
    {
        [, $areaA, $utenteA] = $this->comune('Girasoli');
        [, $areaB, $utenteB] = $this->comune('Ortensie');
        $this->albero($areaA->id, 'GIR-0001', 9.1905, 45.4652);
        $this->albero($areaB->id, 'ORT-0001', 9.1906, 45.4653);

        [$z, $x, $y] = $this->riquadro(9.1905, 45.4652);

        $this->actingAsTenantUser($utenteA);
        $tile = $this->get("/api/v1/portal/tiles/{$z}/{$x}/{$y}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.mapbox-vector-tile')
            ->getContent();
        // Le stringhe del riquadro vettoriale sono in chiaro: il cartellino
        // proprio c'e', quello del vicino no, e ci sono i due strati
        $this->assertStringContainsString('GIR-0001', $tile);
        $this->assertStringNotContainsString('ORT-0001', $tile);
        $this->assertStringContainsString('elementi', $tile);
        $this->assertStringContainsString('aree', $tile);
        $this->assertStringContainsString('Parco Girasoli', $tile);
        $this->assertStringNotContainsString('Parco Ortensie', $tile);

        // Fuori dal territorio il riquadro e' vuoto
        $this->get('/api/v1/portal/tiles/17/1/1')->assertNoContent();

        $this->actingAsTenantUser($utenteB);
        $tileB = $this->get("/api/v1/portal/tiles/{$z}/{$x}/{$y}")->assertOk()->getContent();
        $this->assertStringContainsString('ORT-0001', $tileB);
        $this->assertStringNotContainsString('GIR-0001', $tileB);
    }

    public function test_elenco_e_scheda_restano_dentro_il_territorio(): void
    {
        [, $areaA, $utenteA] = $this->comune('Girasoli');
        [, $areaB] = $this->comune('Ortensie');
        $mio = $this->albero($areaA->id, 'GIR-0001');
        $altrui = $this->albero($areaB->id, 'ORT-0001');
        $this->valutazione($mio);

        $this->actingAsTenantUser($utenteA);
        $elenco = $this->getJson('/api/v1/portal/elementi')->assertOk()->json();
        $this->assertSame(['GIR-0001'], array_column($elenco['data'], 'census_code'));
        $riga = $elenco['data'][0];
        $this->assertSame('Tilia cordata', $riga['specie']);
        $this->assertSame('potare', $riga['stato']);
        $this->assertSame('Da potare', $riga['stato_etichetta']);
        $this->assertSame('C', $riga['vta_classe']);
        $this->assertSame('Parco Girasoli', $riga['area']);
        $this->assertEqualsWithDelta(9.1905, $riga['lon'], 0.0001);

        // Ricerca a parole: per specie oltre che per cartellino
        $this->assertCount(1, $this->getJson('/api/v1/portal/elementi?q=tiglio')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/portal/elementi?q=quercia')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/portal/elementi?stato=potare')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/portal/elementi?stato=sano')->assertOk()->json('data'));
        // L'area di un altro committente non si puo' nemmeno chiedere
        $this->getJson("/api/v1/portal/elementi?area_id={$areaB->id}")->assertNotFound();

        $scheda = $this->getJson("/api/v1/portal/elementi/{$mio}")->assertOk()->json('data');
        $this->assertSame('GIR-0001', $scheda['census_code']);
        $this->assertSame('Tilia cordata', $scheda['tree']['species']);
        $this->assertSame('Point', $scheda['geom_geojson']['type']);
        $this->assertSame('C', $scheda['valutazioni'][0]['failure_class']);
        $this->assertSame('Da sottoporre agli interventi prescritti', $scheda['valutazioni'][0]['outcome_etichetta']);
        // Valutazione non emessa: niente numero di perizia, niente PDF
        $this->assertNull($scheda['valutazioni'][0]['pdf']);
        $this->assertSame('valutazione', $scheda['cronologia'][0]['tipo']);
        // Le note interne della scheda non escono
        $this->assertArrayNotHasKey('notes', $scheda);

        $this->getJson("/api/v1/portal/elementi/{$altrui}")->assertNotFound();
    }

    public function test_le_fotografie_escono_solo_per_gli_elementi_propri(): void
    {
        [, $areaA, $utenteA] = $this->comune('Girasoli');
        [, $areaB] = $this->comune('Ortensie');
        $mio = $this->albero($areaA->id, 'GIR-0001');
        $altrui = $this->albero($areaB->id, 'ORT-0001');

        $this->actingAsTenantUser($this->amministratore);
        $fotoMia = $this->postJson("/api/v1/assets/{$mio}/photos", ['photo' => UploadedFile::fake()->image('tiglio.jpg', 640, 480)])
            ->assertCreated()->json('data.id');
        $fotoAltrui = $this->postJson("/api/v1/assets/{$altrui}/photos", ['photo' => UploadedFile::fake()->image('altro.jpg', 640, 480)])
            ->assertCreated()->json('data.id');

        $this->actingAsTenantUser($utenteA);
        $scheda = $this->getJson("/api/v1/portal/elementi/{$mio}")->assertOk()->json('data');
        $this->assertSame("/api/v1/portal/foto/{$fotoMia}", $scheda['foto'][0]['url']);
        $this->assertSame(1, $this->getJson('/api/v1/portal/elementi')->json('data.0.n_foto'));

        $this->get("/api/v1/portal/foto/{$fotoMia}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get("/api/v1/portal/foto/{$fotoAltrui}")->assertNotFound();
        // La via interna resta chiusa al portale
        $this->get("/api/v1/photos/{$fotoMia}/file")->assertForbidden();
    }

    public function test_i_lavori_seguono_le_regole_del_territorio_e_non_portano_prezzi(): void
    {
        [$clienteA, $areaA, $utenteA] = $this->comune('Girasoli');
        [$clienteB, $areaB] = $this->comune('Ortensie');
        $mio = $this->albero($areaA->id, 'GIR-0001');

        $this->actingAsTenantUser($this->amministratore);
        $nuovo = fn (array $attributi) => WorkOrder::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => WorkOrder::nextCode($this->organizzazione->id),
            'created_by' => $this->amministratore->id,
            'updated_by' => $this->amministratore->id,
            ...$attributi,
        ]);
        $programmato = $nuovo(['title' => 'Potatura del tiglio', 'status' => 'planned', 'area_id' => $areaA->id,
            'planned_start' => now()->addDays(3)->toDateString(), 'planned_end' => now()->addDays(4)->toDateString()]);
        WorkOrderAsset::create(['tenant_id' => $this->organizzazione->id, 'work_order_id' => $programmato->id, 'asset_id' => $mio,
            'planned_quantity' => 1, 'unit' => 'cad']);
        $fatto = $nuovo(['title' => 'Sfalcio del parco', 'status' => 'completed', 'client_id' => $clienteA->id]);
        $fatto->forceFill(['completed_at' => now()->subDays(2)])->save();
        // Misto: area di B con dentro un elemento di A. Non deve uscire a nessuno dei due
        $misto = $nuovo(['title' => 'Intervento riservato', 'status' => 'completed', 'area_id' => $areaB->id]);
        $misto->forceFill(['completed_at' => now()->subDay()])->save();
        WorkOrderAsset::create(['tenant_id' => $this->organizzazione->id, 'work_order_id' => $misto->id, 'asset_id' => $mio]);
        $nuovo(['title' => 'Bozza da non mostrare', 'status' => 'draft', 'area_id' => $areaA->id]);
        $nuovo(['title' => 'Lavoro di Ortensie', 'status' => 'planned', 'client_id' => $clienteB->id]);

        $this->actingAsTenantUser($utenteA);
        $aperti = $this->getJson('/api/v1/portal/lavori')->assertOk()->json();
        $this->assertSame(['Potatura del tiglio'], array_column($aperti['data'], 'title'));
        $this->assertSame(1, $aperti['data'][0]['elementi_totali']);
        $this->assertSame('Pianificato', $aperti['data'][0]['status_etichetta']);
        $this->assertSame('Parco Girasoli', $aperti['data'][0]['area']);

        $fatti = $this->getJson('/api/v1/portal/lavori?stato=fatti')->assertOk()->json();
        $this->assertSame(['Sfalcio del parco'], array_column($fatti['data'], 'title'));
        $this->assertStringNotContainsString('Ortensie', json_encode($fatti));

        $tutti = json_encode($this->getJson('/api/v1/portal/lavori?stato=tutti')->assertOk()->json());
        foreach (['amount', 'price', 'cost', 'unit_price', 'Bozza', 'riservato'] as $parola) {
            $this->assertStringNotContainsString($parola, $tutti);
        }

        $dettaglio = $this->getJson("/api/v1/portal/lavori/{$programmato->id}")->assertOk()->json('data');
        $this->assertSame('GIR-0001', $dettaglio['elementi'][0]['census_code']);
        $this->assertFalse($dettaglio['elementi'][0]['fatto']);
        $this->getJson("/api/v1/portal/lavori/{$misto->id}")->assertNotFound();

        // Nella scheda dell'albero: il lavoro in programma si', il misto no
        $scheda = $this->getJson("/api/v1/portal/elementi/{$mio}")->assertOk()->json('data');
        $this->assertSame(['Potatura del tiglio'], array_column($scheda['lavori'], 'title'));

        $riepilogo = $this->getJson('/api/v1/portal/overview')->assertOk()->json();
        $this->assertSame(1, $riepilogo['counts']['open_orders']);
        $this->assertSame(1, $riepilogo['counts']['completed_orders']);
        $this->assertSame(1, $riepilogo['counts']['trees']);
        $this->assertSame('Potatura del tiglio', $riepilogo['prossimi'][0]['title']);
        $this->assertNotNull($riepilogo['estensione']);
        $this->assertSame(1, $riepilogo['areas'][0]['elementi']);
    }

    public function test_i_documenti_sono_solo_le_perizie_emesse_dei_propri_alberi(): void
    {
        [, $areaA, $utenteA] = $this->comune('Girasoli');
        [, $areaB, $utenteB] = $this->comune('Ortensie');
        $mio = $this->albero($areaA->id, 'GIR-0001');
        $altrui = $this->albero($areaB->id, 'ORT-0001');
        $emessa = $this->valutazione($mio);
        $bozza = $this->valutazione($mio, ['assessed_on' => now('Europe/Rome')->subYear()->toDateString()]);
        $altruiEmessa = $this->valutazione($altrui);

        // Il tecnico emette due perizie: una del Comune A, una del Comune B
        $this->actingAsTenantUser($this->amministratore);
        $this->get("/api/v1/assessments/{$emessa['id']}/perizia-pdf")->assertOk();
        $this->get("/api/v1/assessments/{$altruiEmessa['id']}/perizia-pdf")->assertOk();

        $this->actingAsTenantUser($utenteA);
        $documenti = $this->getJson('/api/v1/portal/documenti')->assertOk()->json('data');
        $this->assertSame([$emessa['id']], array_column($documenti['perizie'], 'id'));
        $this->assertSame('GIR-0001', $documenti['perizie'][0]['census_code']);
        $this->assertNotNull($documenti['perizie'][0]['report_number']);
        $this->assertSame([], $documenti['verbali']);

        $this->get("/api/v1/portal/documenti/perizie/{$emessa['id']}/pdf")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // Una valutazione non emessa non e' un documento, nemmeno se e' propria
        $this->get("/api/v1/portal/documenti/perizie/{$bozza['id']}/pdf")->assertNotFound();
        $this->assertNull(TreeAssessment::query()->find($bozza['id'])->report_issued_at);
        // Quella dell'altro Comune non esiste, per questo portale
        $this->get("/api/v1/portal/documenti/perizie/{$altruiEmessa['id']}/pdf")->assertNotFound();
        // E la via interna resta chiusa
        $this->get("/api/v1/assessments/{$emessa['id']}/perizia-pdf")->assertForbidden();

        $riepilogo = $this->getJson('/api/v1/portal/overview')->assertOk()->json();
        $this->assertSame(1, $riepilogo['counts']['documenti']);
        // La valutazione di un anno fa non e' una novita' degli ultimi trenta giorni
        $this->assertSame(1, $riepilogo['recenti']['valutazioni']);

        $this->actingAsTenantUser($utenteB);
        $this->assertSame([$altruiEmessa['id']], array_column($this->getJson('/api/v1/portal/documenti')->json('data.perizie'), 'id'));
    }

    public function test_la_richiesta_porta_il_lavoro_che_ne_e_nato(): void
    {
        [, $areaA, $utenteA] = $this->comune('Girasoli');

        $this->actingAsTenantUser($utenteA);
        $richiesta = $this->postJson('/api/v1/portal/requests', ['description' => 'Ramo spezzato sul vialetto.', 'area_id' => $areaA->id])
            ->assertCreated()->json('data');
        $this->assertNull($richiesta['lavoro']);

        // L'ufficio apre il lavoro dalla segnalazione
        $this->actingAsTenantUser($this->amministratore);
        $this->postJson("/api/v1/issues/{$richiesta['id']}/work-order")->assertSuccessful();
        $lavoro = WorkOrder::query()->where('origin', 'issue')->where('origin_id', $richiesta['id'])->firstOrFail();
        // L'ordine nasce in bozza: il Comune lo vede quando l'ufficio lo mette in programma
        $lavoro->forceFill(['status' => 'planned', 'planned_start' => now()->addDays(2)->toDateString()])->save();
        $lavoroId = $lavoro->id;

        $this->actingAsTenantUser($utenteA);
        $mie = $this->getJson('/api/v1/portal/requests')->assertOk()->json('data');
        $this->assertSame($lavoroId, $mie[0]['lavoro']['id']);
        $this->assertSame(WorkOrder::STATUS_LABELS[WorkOrder::query()->find($lavoroId)->status], $mie[0]['lavoro']['status_etichetta']);

        $lavori = $this->getJson('/api/v1/portal/lavori')->assertOk()->json('data');
        $this->assertSame($richiesta['code'], $lavori[0]['richiesta']['code']);
    }
}
