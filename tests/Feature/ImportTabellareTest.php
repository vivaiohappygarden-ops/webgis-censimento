<?php

namespace Tests\Feature;

use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Import di censimenti consegnati come tabella (Excel .xlsx o CSV) dentro
 * il flusso dell'import generico: stessa analisi, stessa mappatura, stessa
 * verifica con pre-conteggio; in più la scelta delle colonne di coordinate
 * e del sistema di riferimento. Le regole di casa qui contano doppio:
 * anteprima = esecuzione, e una riga senza coordinate valide si scarta
 * dichiarandola con il suo numero, mai inventando una posizione.
 *
 * La lettura del foglio passa da ogr2ogr: i test tabellari si saltano
 * dove GDAL non c'è (in produzione è un requisito, lo usa l'export CAM).
 */
class ImportTabellareTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->area = $this->createArea($this->organization);
        $this->actingAsTenantUser($this->user);
    }

    private function richiedeOgr2ogr(): void
    {
        if (! (new \App\Services\Export\CamExporter)->ogr2ogrAvailable()) {
            $this->markTestSkipped('ogr2ogr non disponibile.');
        }
    }

    private function analizzaFile(UploadedFile $file): array
    {
        return $this->post('/api/v1/imports/analizza', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->json('data');
    }

    private function csv(string $contenuto, string $nome = 'censimento.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, $contenuto);
    }

    /** La fixture: un VERO .xlsx piccolo (due fogli, virgole decimali, una
     * riga senza coordinate, una con testo al posto del numero); si
     * rigenera con tests/Fixtures/genera_censimento_prova.php. */
    private function fixtureXlsx(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/censimento_prova.xlsx'),
            'censimento_prova.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    public function test_csv_analysis_proposes_coordinate_columns_and_reference_systems(): void
    {
        $this->richiedeOgr2ogr();

        $analisi = $this->analizzaFile($this->csv(
            "ETICHETTA;SPECIE;LON;LAT;ALTEZZA\nC-1;Tilia cordata;9,1912;45,4641;12,5\n",
        ));

        $this->assertSame(1, $analisi['totale']);
        $this->assertEqualsCanonicalizing(
            ['ETICHETTA', 'SPECIE', 'LON', 'LAT', 'ALTEZZA'],
            array_column($analisi['colonne'], 'nome'),
        );
        // Il blocco tabellare c'è solo per i fogli, con la proposta delle
        // colonne di coordinate e l'elenco dei sistemi ammessi dal server
        $this->assertSame('LON', $analisi['tabellare']['proposta_x']);
        $this->assertSame('LAT', $analisi['tabellare']['proposta_y']);
        $sistemi = array_column($analisi['tabellare']['sistemi'], 'valore');
        $this->assertContains('4326', $sistemi);
        $this->assertContains('3003', $sistemi);
        // La mappatura degli attributi resta quella dell'import generico
        $this->assertSame('specie', $analisi['proposta']['SPECIE']);
    }

    public function test_shapefile_analysis_has_no_tabular_block(): void
    {
        $analisi = $this->analizzaFile(UploadedFile::fake()->createWithContent('dati.geojson', json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [9.19, 45.464]],
                'properties' => ['ID' => 'G-1'],
            ]],
        ])));

        $this->assertArrayNotHasKey('tabellare', $analisi);
    }

    public function test_csv_import_reads_decimal_commas_and_declares_rows_without_coordinates(): void
    {
        $this->richiedeOgr2ogr();
        $albero = $this->makeObjectType($this->organization, 'P', 'P103108');

        $analisi = $this->analizzaFile($this->csv(
            "ETICHETTA;SPECIE;LON;LAT;ALTEZZA\n"
            ."C-1;Tilia cordata;9,1912;45,4641;12,5\n"
            ."C-2;Acer campestre;9.1920;45.4650;8\n"
            ."C-3;Quercus robur;;;10\n",
        ));

        $payload = [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ETICHETTA' => 'codice_censimento', 'SPECIE' => 'specie', 'ALTEZZA' => 'altezza_m'],
            'default_object_type_id' => $albero->id,
            'coordinate' => ['colonna_x' => 'LON', 'colonna_y' => 'LAT', 'epsg' => '4326'],
        ];

        // Pre-conteggio: la riga senza coordinate è scartata e DICHIARATA
        // con il suo numero di riga NEL FOGLIO (C-3 sta alla riga 4:
        // l'intestazione è la riga 1), non persa in silenzio
        $prova = $this->postJson('/api/v1/imports/generico', [...$payload, 'dry_run' => true])
            ->assertOk()->json('data');
        $this->assertSame(2, $prova['importable']);
        $this->assertSame(1, $prova['errors_total']);
        $this->assertStringContainsString('riga #4', $prova['errors'][0]['error']);
        $this->assertStringContainsString('coordinate', $prova['errors'][0]['error']);
        $this->assertSame(0, Asset::query()->count());

        // Anteprima = esecuzione: stessi conteggi, stesso percorso
        $esito = $this->postJson('/api/v1/imports/generico', [...$payload, 'dry_run' => false])
            ->assertOk()->json('data');
        $this->assertSame(2, $esito['imported']);
        $this->assertSame($prova['errors_total'], $esito['errors_total']);

        // La virgola decimale italiana vale sia nelle coordinate sia nelle misure
        $c1 = Asset::query()->with('tree')->where('census_code', 'C-1')->firstOrFail();
        $punto = DB::selectOne('SELECT ST_X(geom::geometry) AS x, ST_Y(geom::geometry) AS y FROM assets WHERE id = ?', [$c1->id]);
        $this->assertEqualsWithDelta(9.1912, (float) $punto->x, 0.00001);
        $this->assertEqualsWithDelta(45.4641, (float) $punto->y, 0.00001);
        $this->assertEqualsWithDelta(12.5, (float) $c1->tree->height_m, 0.001);
    }

    public function test_gauss_boaga_coordinates_are_reprojected_to_wgs84(): void
    {
        $this->richiedeOgr2ogr();
        $albero = $this->makeObjectType($this->organization, 'P', 'P103108');

        // (1514881.543, 5034534.593) in EPSG:3003 è (9.19, 45.464) WGS84
        // (trasformazione di riferimento calcolata con ogr2ogr/PROJ);
        // la seconda riga è in gradi: con un sistema metrico va scartata
        // e dichiarata, mai riproiettata in un punto inventato
        $analisi = $this->analizzaFile($this->csv(
            "ETICHETTA;EST;NORD\n"
            ."GB-1;1514881,543;5034534,593\n"
            ."GB-2;9,19;45,464\n",
        ));

        $report = $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ETICHETTA' => 'codice_censimento'],
            'default_object_type_id' => $albero->id,
            'coordinate' => ['colonna_x' => 'EST', 'colonna_y' => 'NORD', 'epsg' => '3003'],
            'dry_run' => false,
        ])->assertOk()->json('data');

        $this->assertSame(1, $report['imported']);
        $this->assertSame(1, $report['errors_total']);
        // GB-2 è la seconda riga di dati, cioè la riga 3 del foglio
        $this->assertStringContainsString('riga #3', $report['errors'][0]['error']);
        $this->assertStringContainsString('gradi', $report['errors'][0]['error']);

        $id = Asset::query()->where('census_code', 'GB-1')->firstOrFail()->id;
        $punto = DB::selectOne('SELECT ST_X(geom::geometry) AS x, ST_Y(geom::geometry) AS y FROM assets WHERE id = ?', [$id]);
        $this->assertEqualsWithDelta(9.19, (float) $punto->x, 0.001);
        $this->assertEqualsWithDelta(45.464, (float) $punto->y, 0.001);
    }

    public function test_metric_values_declared_as_wgs84_are_discarded_not_invented(): void
    {
        $this->richiedeOgr2ogr();
        $albero = $this->makeObjectType($this->organization, 'P', 'P103108');

        $analisi = $this->analizzaFile($this->csv(
            "ETICHETTA;LON;LAT\nM-1;1514881;5034534\n",
        ));

        $report = $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ETICHETTA' => 'codice_censimento'],
            'default_object_type_id' => $albero->id,
            'coordinate' => ['colonna_x' => 'LON', 'colonna_y' => 'LAT', 'epsg' => '4326'],
            'dry_run' => true,
        ])->assertOk()->json('data');

        $this->assertSame(0, $report['importable']);
        $this->assertSame(1, $report['errors_total']);
        $this->assertStringContainsString('fuori dai limiti', $report['errors'][0]['error']);
    }

    public function test_old_xls_is_refused_with_the_advice_to_resave_as_xlsx(): void
    {
        $risposta = $this->post('/api/v1/imports/analizza', [
            'file' => UploadedFile::fake()->createWithContent('vecchio.xls', 'contenuto binario qualsiasi'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertStringContainsString('.xlsx', $risposta->json('errors.file.0'));
    }

    public function test_a_renamed_xls_pretending_to_be_xlsx_is_refused(): void
    {
        // Un vero .xlsx è un archivio zip ("PK"): questo non lo è
        $risposta = $this->post('/api/v1/imports/analizza', [
            'file' => UploadedFile::fake()->createWithContent('finto.xlsx', "\xD0\xCF\x11\xE0 contenuto ole"),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertStringContainsString('vero .xlsx', $risposta->json('errors.file.0'));
    }

    public function test_real_xlsx_fixture_roundtrip_first_sheet_commas_and_declared_discards(): void
    {
        $this->richiedeOgr2ogr();
        $albero = $this->makeObjectType($this->organization, 'P', 'P103108');

        $analisi = $this->analizzaFile($this->fixtureXlsx());

        // Due fogli nel file: si usa il primo e lo si dice
        $this->assertSame(4, $analisi['totale']);
        $this->assertStringContainsString('Rilievo', implode(' ', $analisi['avvisi']));
        $this->assertSame('LONGITUDINE', $analisi['tabellare']['proposta_x']);
        $this->assertSame('LATITUDINE', $analisi['tabellare']['proposta_y']);

        $payload = [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ETICHETTA' => 'codice_censimento', 'SPECIE' => 'specie', 'ALTEZZA' => 'altezza_m'],
            'default_object_type_id' => $albero->id,
            'coordinate' => ['colonna_x' => 'LONGITUDINE', 'colonna_y' => 'LATITUDINE', 'epsg' => '4326'],
        ];

        // Pre-conteggio: T-103 senza coordinate e T-104 con testo al posto
        // del numero sono scarti dichiarati riga per riga, con i numeri
        // del foglio (righe 4 e 5: la riga 1 è l'intestazione)
        $prova = $this->postJson('/api/v1/imports/generico', [...$payload, 'dry_run' => true])
            ->assertOk()->json('data');
        $this->assertSame(2, $prova['importable']);
        $this->assertSame(2, $prova['errors_total']);
        $righe = implode(' ', array_column($prova['errors'], 'error'));
        $this->assertStringContainsString('riga #4', $righe);
        $this->assertStringContainsString('riga #5', $righe);

        // Esecuzione identica all'anteprima
        $this->postJson('/api/v1/imports/generico', [...$payload, 'dry_run' => false])
            ->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.errors_total', 2);

        // T-102 aveva coordinate e altezza con la virgola decimale
        $t102 = Asset::query()->with('tree')->where('census_code', 'T-102')->firstOrFail();
        $punto = DB::selectOne('SELECT ST_X(geom::geometry) AS x, ST_Y(geom::geometry) AS y FROM assets WHERE id = ?', [$t102->id]);
        $this->assertEqualsWithDelta(9.191, (float) $punto->x, 0.00001);
        $this->assertEqualsWithDelta(45.465, (float) $punto->y, 0.00001);
        $this->assertEqualsWithDelta(8.5, (float) $t102->tree->height_m, 0.001);
        $this->assertSame(2, Asset::query()->count());
    }

    public function test_coordinate_column_choices_are_validated(): void
    {
        $this->richiedeOgr2ogr();
        $analisi = $this->analizzaFile($this->csv("ETICHETTA;LON;LAT\nV-1;9.19;45.464\n"));
        $base = [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => [],
            'dry_run' => true,
        ];

        // Stessa colonna per X e Y: rifiutata
        $this->postJson('/api/v1/imports/generico', [
            ...$base, 'coordinate' => ['colonna_x' => 'LON', 'colonna_y' => 'LON', 'epsg' => '4326'],
        ])->assertUnprocessable();

        // Colonna che non esiste nel file: un errore chiaro, non mille
        // scarti tutti uguali
        $this->postJson('/api/v1/imports/generico', [
            ...$base, 'coordinate' => ['colonna_x' => 'NON_CE', 'colonna_y' => 'LAT', 'epsg' => '4326'],
        ])->assertUnprocessable();

        // Codice EPSG fuori dall'elenco del server: rifiutato dalla
        // validazione (finirebbe in una riga di comando ogr2ogr)
        $this->postJson('/api/v1/imports/generico', [
            ...$base, 'coordinate' => ['colonna_x' => 'LON', 'colonna_y' => 'LAT', 'epsg' => '9999'],
        ])->assertUnprocessable();
    }

    public function test_table_import_requires_create_permission_like_the_other_channels(): void
    {
        $viewer = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $viewer->assignRole('cliente');
        $this->actingAsTenantUser($viewer);

        $this->post('/api/v1/imports/analizza', [
            'file' => $this->csv("ETICHETTA;LON;LAT\nP-1;9.19;45.464\n"),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_table_token_is_tenant_bound(): void
    {
        $this->richiedeOgr2ogr();
        $analisi = $this->analizzaFile($this->csv("ETICHETTA;LON;LAT\nT-1;9.19;45.464\n"));

        // Un altro tenant non può consumare l'analisi di questo
        [$altraOrg, $altroUtente] = $this->createTenantUser();
        $altraArea = $this->createArea($altraOrg);
        $this->actingAsTenantUser($altroUtente);

        $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $altraArea->id,
            'mappatura' => [],
            'coordinate' => ['colonna_x' => 'LON', 'colonna_y' => 'LAT', 'epsg' => '4326'],
            'dry_run' => true,
        ])->assertUnprocessable();
    }
}
