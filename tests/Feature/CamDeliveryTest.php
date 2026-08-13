<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Locality;
use App\Models\Site;
use App\Services\Export\CamExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CamDeliveryTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organization, $this->user] = $this->createTenantUser();

        $client = Client::create(['tenant_id' => $this->organization->id, 'name' => 'Comune CAM', 'client_type' => 'public']);
        $site = Site::create(['tenant_id' => $this->organization->id, 'client_id' => $client->id, 'name' => 'Milano', 'istat_code' => '015146']);
        $locality = Locality::create(['tenant_id' => $this->organization->id, 'site_id' => $site->id, 'name' => 'Zona 1', 'code' => 'Z01']);
        $this->area = \App\Models\Area::create([
            'tenant_id' => $this->organization->id,
            'locality_id' => $locality->id,
            'name' => 'Parco CAM',
            'code' => 'AREA-CAM',
            'geom' => \App\Support\Geometry::toEwkb($this->squarePolygon(), forceMultiPolygon: true),
        ]);
        $this->actingAsTenantUser($this->user);
    }

    private function makeAssetWithPhoto(string $typeCode, string $censusCode, string $photoName): string
    {
        $type = \App\Models\CatalogObjectType::query()->where('code', $typeCode)->first()
            ?? $this->makeObjectType($this->organization, 'P', $typeCode);
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $type->id,
            'census_code' => $censusCode,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
        $this->post("/api/v1/assets/{$id}/photos", [
            'photo' => UploadedFile::fake()->image($photoName, 100, 100),
            'category' => 'census',
        ])->assertCreated();

        return $id;
    }

    /** Scarica la consegna e la apre come archivio zip. */
    private function download(string $format): \ZipArchive
    {
        $response = $this->get('/api/v1/exports/cam/delivery?format='.$format)->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'consegna').'.zip';
        copy($response->getFile()->getPathname(), $path);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));

        return $zip;
    }

    public function test_geojson_delivery_bundles_layers_photos_and_manifest(): void
    {
        $this->makeAssetWithPhoto('P103108', 'ALB-1', 'albero.jpg');

        $hedgeType = $this->makeObjectType($this->organization, 'L', 'L103104');
        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $hedgeType->id,
            'census_code' => 'SIEPE-1',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[9.19, 45.465], [9.191, 45.465]]],
        ])->assertCreated();

        $zip = $this->download('geojson');

        // Un file per layer non vuoto, nominato col codice ISTAT del comune
        $this->assertNotFalse($zip->locateName('015146_P1.geojson'));
        $this->assertNotFalse($zip->locateName('015146_L1.geojson'));
        $this->assertNotFalse($zip->locateName('015146_S3.geojson'));
        $this->assertFalse($zip->locateName('015146_P2.geojson'));
        $this->assertNotFalse($zip->locateName('FOTO/albero.jpg'));
        $this->assertNotFalse($zip->locateName('LEGGIMI.txt'));

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame(['P1' => 1, 'L1' => 1, 'S3' => 1], $manifest['layer']);
        $this->assertSame(1, $manifest['foto']);
        $this->assertSame(0, $manifest['foto_mancanti']);

        // Il campo FOTO referenzia il file consegnato e la chiave interna
        // di abbinamento non finisce nei file
        $p1 = json_decode($zip->getFromName('015146_P1.geojson'), true);
        $this->assertSame('albero.jpg', $p1['features'][0]['properties']['FOTO']);
        $this->assertArrayNotHasKey('_asset_id', $p1['features'][0]['properties']);
    }

    public function test_duplicate_photo_names_are_disambiguated(): void
    {
        $this->makeAssetWithPhoto('P103108', 'ALB-1', 'foto.jpg');
        $this->makeAssetWithPhoto('P103108', 'ALB-2', 'foto.jpg');

        $zip = $this->download('geojson');

        $this->assertNotFalse($zip->locateName('FOTO/foto.jpg'));
        $features = json_decode($zip->getFromName('015146_P1.geojson'), true)['features'];
        $names = collect($features)->pluck('properties.FOTO')->sort()->values()->all();
        $this->assertCount(2, array_unique($names));
        foreach ($names as $name) {
            $this->assertNotFalse($zip->locateName("FOTO/{$name}"), "FOTO/{$name} assente dalla consegna");
        }
    }

    public function test_shapefile_delivery_contains_sidecar_files(): void
    {
        if (! app(CamExporter::class)->ogr2ogrAvailable()) {
            $this->markTestSkipped('GDAL non disponibile');
        }
        $this->makeAssetWithPhoto('P103108', 'ALB-1', 'albero.jpg');

        $zip = $this->download('shapefile');

        foreach (['shp', 'dbf', 'prj'] as $ext) {
            $this->assertNotFalse($zip->locateName("015146_P1.{$ext}"), "015146_P1.{$ext} assente");
        }
        $this->assertNotFalse($zip->locateName('FOTO/albero.jpg'));
        $this->assertNotFalse($zip->locateName('manifest.json'));
    }

    public function test_empty_census_yields_a_clear_error(): void
    {
        \App\Models\Area::query()->delete();

        $this->get('/api/v1/exports/cam/delivery?format=geojson', ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }
}
