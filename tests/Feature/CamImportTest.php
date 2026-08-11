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
