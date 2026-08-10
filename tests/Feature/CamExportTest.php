<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Locality;
use App\Models\Site;
use App\Services\Export\CamExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CamExportTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    private $treeType;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();

        $client = Client::create([
            'tenant_id' => $this->organization->id, 'name' => 'Comune CAM', 'client_type' => 'public',
        ]);
        $site = Site::create([
            'tenant_id' => $this->organization->id, 'client_id' => $client->id,
            'name' => 'Milano', 'istat_code' => '015146',
        ]);
        $locality = Locality::create([
            'tenant_id' => $this->organization->id, 'site_id' => $site->id,
            'name' => 'Zona 1', 'code' => 'Z01', 'survey_zone_code' => 'ZRIL001',
        ]);

        $this->area = \App\Models\Area::create([
            'tenant_id' => $this->organization->id,
            'locality_id' => $locality->id,
            'name' => 'Parco CAM',
            'code' => 'AREA-CAM',
            'manager' => 'Happy Garden',
            'geom' => \App\Support\Geometry::toEwkb($this->squarePolygon(), forceMultiPolygon: true),
        ]);

        $this->treeType = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function createExportableTree(): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => 'CAM-001',
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Platanus', 'species' => 'Platanus x acerifolia', 'height_m' => 22, 'dbh_cm' => 55],
        ])->assertOk();

        return $id;
    }

    public function test_cam_p1_geojson_export_has_master_shapefile_fields(): void
    {
        $this->createExportableTree();

        $response = $this->get('/api/v1/exports/cam?layer=P1&format=geojson')->assertOk();
        $collection = json_decode($response->streamedContent(), true);

        $this->assertSame('FeatureCollection', $collection['type']);
        $this->assertCount(1, $collection['features']);

        $props = $collection['features'][0]['properties'];
        $this->assertSame('015146', $props['CODE_ISTAT']);
        $this->assertSame('Z01', $props['ZONA']);
        $this->assertSame('ZRIL001', $props['ID_ZRIL']);
        $this->assertSame('AREA-CAM', $props['AREA']);
        $this->assertSame('CAM-001', $props['OBJ_ID']);
        $this->assertSame('1', $props['TP']);
        $this->assertSame('03', $props['TS']);
        $this->assertSame('P103108', $props['CODICE']);
        $this->assertSame('Platanus', $props['GENERE']);
        $this->assertSame('Platanus x acerifolia', $props['SPECIE']);
        $this->assertEquals(22.0, $props['H_m']);
        $this->assertEquals(55.0, $props['DIAM_TRONC']);
        $this->assertSame('Pianta viva', $props['STATO']);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $props['DATA_INI']);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $props['DATA_AGG']);
    }

    public function test_cam_s3_export_contains_management_areas(): void
    {
        $response = $this->get('/api/v1/exports/cam?layer=S3&format=geojson')->assertOk();
        $collection = json_decode($response->streamedContent(), true);

        $this->assertCount(1, $collection['features']);
        $props = $collection['features'][0]['properties'];

        $this->assertSame('S325500', $props['CODICE']);
        $this->assertSame('Parco CAM', $props['NOME_AREA']);
        $this->assertSame('Happy Garden', $props['GESTORE']);
        $this->assertGreaterThan(0, $props['AREA_mq']);
        $this->assertGreaterThan(0, $props['PERIM_m']);
    }

    public function test_cam_shapefile_export(): void
    {
        if (! app(CamExporter::class)->ogr2ogrAvailable()) {
            // Senza GDAL il formato shapefile deve fallire in modo chiaro, non con un 500
            $this->get('/api/v1/exports/cam?layer=P1&format=shapefile')->assertUnprocessable();
            $this->markTestSkipped('ogr2ogr non disponibile: verificato solo il fallimento pulito.');
        }

        $this->createExportableTree();

        $response = $this->get('/api/v1/exports/cam?layer=P1&format=shapefile');
        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
    }
}
