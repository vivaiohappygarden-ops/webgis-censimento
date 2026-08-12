<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Locality;
use App\Models\Site;
use App\Services\Export\CamExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CamImportTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant sorgente: un albero completo esportato in formato CAM
        [$this->organization, $this->user] = $this->createTenantUser();

        $client = Client::create(['tenant_id' => $this->organization->id, 'name' => 'Comune CAM', 'client_type' => 'public']);
        $site = Site::create(['tenant_id' => $this->organization->id, 'client_id' => $client->id, 'name' => 'Milano', 'istat_code' => '015146']);
        $locality = Locality::create(['tenant_id' => $this->organization->id, 'site_id' => $site->id, 'name' => 'Zona 1', 'code' => 'Z01']);
        $this->area = \App\Models\Area::create([
            'tenant_id' => $this->organization->id,
            'locality_id' => $locality->id,
            'name' => 'Parco Origine',
            'code' => 'AREA-SRC',
            'geom' => \App\Support\Geometry::toEwkb($this->squarePolygon(), forceMultiPolygon: true),
        ]);
        $type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);

        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $type->id,
            'census_code' => 'ALB-RT-1',
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');
        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Platanus', 'species' => 'Platanus x acerifolia', 'height_m' => 22, 'dbh_cm' => 55],
        ])->assertOk();
    }

    /** @return array{0: \App\Models\Organization, 1: \App\Models\User, 2: \App\Models\Area} */
    private function makeDestinationTenant(): array
    {
        [$org, $user] = $this->createTenantUser();
        $client = Client::create(['tenant_id' => $org->id, 'name' => 'Comune Dest', 'client_type' => 'public']);
        $site = Site::create(['tenant_id' => $org->id, 'client_id' => $client->id, 'name' => 'Bergamo', 'istat_code' => '016024']);
        $locality = Locality::create(['tenant_id' => $org->id, 'site_id' => $site->id, 'name' => 'Zona A', 'code' => 'ZA1']);
        $area = \App\Models\Area::create([
            'tenant_id' => $org->id,
            'locality_id' => $locality->id,
            'name' => 'Parco Destinazione',
            'code' => 'AREA-DST',
            'geom' => \App\Support\Geometry::toEwkb($this->squarePolygon(), forceMultiPolygon: true),
        ]);
        $this->makeObjectType($org, 'P', 'P103108');

        return [$org, $user, $area];
    }

    public function test_roundtrip_export_import_geojson(): void
    {
        $collection = app(CamExporter::class)->featureCollection('P1', 7791);
        $this->assertCount(1, $collection['features']);

        [, $destUser, $destArea] = $this->makeDestinationTenant();
        $this->actingAsTenantUser($destUser);

        $file = UploadedFile::fake()->createWithContent('P1.geojson', json_encode($collection));

        // Dry-run: analisi senza scrivere nulla
        $this->post('/api/v1/imports/cam', [
            'file' => $file,
            'area_id' => $destArea->id,
            'dry_run' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.importable', 1)
            ->assertJsonPath('data.imported', 0);
        $this->assertSame(0, Asset::query()->count());

        // Import vero
        $this->post('/api/v1/imports/cam', [
            'file' => UploadedFile::fake()->createWithContent('P1.geojson', json_encode($collection)),
            'area_id' => $destArea->id,
            'dry_run' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.imported', 1);

        $asset = Asset::query()->with('tree')->firstOrFail();
        $this->assertSame('ALB-RT-1', $asset->census_code);
        $this->assertSame($destArea->id, $asset->area_id);
        $this->assertSame('shapefile_import', $asset->survey_method);
        $this->assertSame('Platanus', $asset->tree->genus);
        $this->assertSame('Platanus x acerifolia', $asset->tree->species);
        $this->assertEquals(22, $asset->tree->height_m);
        $this->assertEquals(55, $asset->tree->dbh_cm);

        // DATA_INI (GGMMAAAA) diventa la data di inizio validità
        $this->assertNotNull($asset->valid_from);

        // Un secondo import dello stesso file non crea doppioni (OBJ_ID già presente)
        $this->post('/api/v1/imports/cam', [
            'file' => UploadedFile::fake()->createWithContent('P1.geojson', json_encode($collection)),
            'area_id' => $destArea->id,
            'dry_run' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.errors_total', 1);
        $this->assertSame(1, Asset::query()->count());
    }

    public function test_roundtrip_export_import_shapefile(): void
    {
        $exporter = app(CamExporter::class);
        if (! $exporter->ogr2ogrAvailable()) {
            $this->markTestSkipped('ogr2ogr non disponibile.');
        }

        $collection = $exporter->featureCollection('P1', 7791);
        $zipPath = $exporter->toShapefileZip($collection, 'P1', 7791);

        [, $destUser, $destArea] = $this->makeDestinationTenant();
        $this->actingAsTenantUser($destUser);

        $this->post('/api/v1/imports/cam', [
            'file' => new UploadedFile($zipPath, 'P1.zip', 'application/zip', null, true),
            'area_id' => $destArea->id,
            'dry_run' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.imported', 1);

        $asset = Asset::query()->with('tree')->firstOrFail();
        $this->assertSame('ALB-RT-1', $asset->census_code);
        $this->assertSame('Platanus x acerifolia', $asset->tree->species);
        $this->assertEquals(22, $asset->tree->height_m);

        // La riproiezione RDN2008 -> WGS84 deve tornare alle coordinate originali
        $coords = $this->getJson('/api/v1/assets/'.$asset->id)->json('data.geom_geojson.coordinates');
        $this->assertEqualsWithDelta(9.1905, $coords[0], 0.00001);
        $this->assertEqualsWithDelta(45.4652, $coords[1], 0.00001);
    }

    public function test_layer_specific_fields_import_into_attributes(): void
    {
        [, $destUser, $destArea] = $this->makeDestinationTenant();
        $destOrg = $destArea->tenant_id;
        $hedge = $this->makeObjectType(\App\Models\Organization::findOrFail($destOrg), 'L', 'L103104');
        $this->actingAsTenantUser($destUser);

        $collection = [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => [[9.1900, 45.4650], [9.1910, 45.4650]]],
                'properties' => [
                    'CODICE' => 'L103104', 'OBJ_ID' => 'SIEPE-IMP-1',
                    'GENERE' => 'Ligustrum', 'SPECIE' => 'Ligustrum vulgare',
                    'H_m' => '1,6', 'LARG_m' => 0.8, 'DATA_RIL' => '05082026',
                ],
            ]],
        ];

        $this->post('/api/v1/imports/cam', [
            'file' => UploadedFile::fake()->createWithContent('L1.geojson', json_encode($collection)),
            'area_id' => $destArea->id,
            'dry_run' => '0',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.imported', 1);

        $asset = Asset::query()->where('census_code', 'SIEPE-IMP-1')->firstOrFail();
        $this->assertSame('Ligustrum', $asset->attributes['genere']);
        $this->assertSame('Ligustrum vulgare', $asset->attributes['specie']);
        $this->assertEquals(1.6, $asset->attributes['altezza_m']);
        $this->assertEquals(0.8, $asset->attributes['larghezza_m']);
        $this->assertSame('2026-08-05', $asset->surveyed_at->toDateString());

        // I campi standard MD vengono definiti sul tipo, così la scheda li
        // mostra e una modifica successiva li accetta
        $keys = \App\Models\CustomField::query()->withoutGlobalScopes()
            ->where('object_type_id', $hedge->id)->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['genere', 'specie', 'altezza_m', 'larghezza_m'], $keys);
        $this->patchJson("/api/v1/assets/{$asset->id}", [
            'attributes' => ['genere' => 'Ligustrum', 'specie' => 'Ligustrum vulgare', 'altezza_m' => 1.8, 'larghezza_m' => 0.8],
            'version' => $asset->version,
        ])->assertOk();
    }

    public function test_area_perimeter_features_are_skipped_on_import(): void
    {
        [, $destUser, $destArea] = $this->makeDestinationTenant();
        $destOrgModel = \App\Models\Organization::findOrFail($destArea->tenant_id);
        $this->makeObjectType($destOrgModel, 'S', 'S325500', ['cam_layer' => 'S3']);
        $this->makeObjectType($destOrgModel, 'S', 'S327552', ['cam_layer' => 'S3']);
        $this->actingAsTenantUser($destUser);

        $feature = fn (string $codice, string $objId) => [
            'type' => 'Feature',
            'geometry' => $this->squarePolygon(),
            'properties' => ['CODICE' => $codice, 'OBJ_ID' => $objId],
        ];
        $collection = [
            'type' => 'FeatureCollection',
            'features' => [$feature('S325500', 'PERIM-1'), $feature('S327552', 'GIOCO-IMP-1')],
        ];

        // Il perimetro dell'area di gestione non diventa un elemento censito
        $this->post('/api/v1/imports/cam', [
            'file' => UploadedFile::fake()->createWithContent('S3.geojson', json_encode($collection)),
            'area_id' => $destArea->id,
            'dry_run' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.warnings_total', 1);

        $this->assertSame(1, Asset::query()->count());
        $this->assertSame('GIOCO-IMP-1', Asset::query()->firstOrFail()->census_code);
    }

    public function test_inverted_dates_are_rejected_in_dry_run_not_at_insert(): void
    {
        [, $destUser, $destArea] = $this->makeDestinationTenant();
        $this->actingAsTenantUser($destUser);

        $collection = [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => $this->pointGeometry(),
                // DATA_INI assente -> varrebbe oggi; DATA_FINE nel passato
                'properties' => ['CODICE' => 'P103108', 'OBJ_ID' => 'DT-1', 'DATA_FINE' => '01012024'],
            ]],
        ];

        // Il dry-run segnala la riga come errore (niente 500 all'import vero)
        $this->post('/api/v1/imports/cam', [
            'file' => UploadedFile::fake()->createWithContent('P1.geojson', json_encode($collection)),
            'area_id' => $destArea->id,
            'dry_run' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.importable', 0)
            ->assertJsonPath('data.errors_total', 1);

        $this->post('/api/v1/imports/cam', [
            'file' => UploadedFile::fake()->createWithContent('P1.geojson', json_encode($collection)),
            'area_id' => $destArea->id,
            'dry_run' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.imported', 0);
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_unknown_codice_is_rejected_per_row(): void
    {
        [, $destUser, $destArea] = $this->makeDestinationTenant();
        $this->actingAsTenantUser($destUser);

        $collection = [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => $this->pointGeometry(),
                'properties' => ['CODICE' => 'P999999', 'OBJ_ID' => 'X-1'],
            ]],
        ];

        $this->post('/api/v1/imports/cam', [
            'file' => UploadedFile::fake()->createWithContent('P1.geojson', json_encode($collection)),
            'area_id' => $destArea->id,
            'dry_run' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.importable', 0)
            ->assertJsonPath('data.errors_total', 1);
    }
}
