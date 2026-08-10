<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class GeoJsonImportTest extends TestCase
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
        $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function geojsonFile(): UploadedFile
    {
        $collection = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [9.1905, 45.4652]],
                    'properties' => ['codice' => 'P103108', 'census_code' => 'IMP-001', 'note' => 'primo albero', 'extra_ignota' => 'x'],
                ],
                [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [9.1910, 45.4655]],
                    'properties' => ['codice' => 'P103108', 'census_code' => 'IMP-002'],
                ],
                [ // geometria non ammessa per un albero
                    'type' => 'Feature',
                    'geometry' => ['type' => 'LineString', 'coordinates' => [[9.19, 45.46], [9.191, 45.461]]],
                    'properties' => ['codice' => 'P103108', 'census_code' => 'IMP-003'],
                ],
                [ // codice sconosciuto e nessun tipo di default
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [9.1920, 45.4660]],
                    'properties' => ['codice' => 'ZZZZZZZ'],
                ],
            ],
        ];

        return UploadedFile::fake()->createWithContent('censimento.geojson', json_encode($collection));
    }

    public function test_dry_run_reports_without_importing(): void
    {
        $response = $this->post('/api/v1/imports/geojson', [
            'file' => $this->geojsonFile(),
            'area_id' => $this->area->id,
            'dry_run' => '1',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.importable', 2)
            ->assertJsonPath('data.errors_total', 2)
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.dry_run', true);

        $this->assertContains('extra_ignota', $response->json('data.dropped_properties'));
        $this->assertDatabaseMissing('assets', ['census_code' => 'IMP-001']);
    }

    public function test_real_import_creates_assets_and_blocks_duplicates(): void
    {
        $this->post('/api/v1/imports/geojson', [
            'file' => $this->geojsonFile(),
            'area_id' => $this->area->id,
            'dry_run' => '0',
        ])->assertOk()->assertJsonPath('data.imported', 2);

        $this->assertDatabaseHas('assets', ['census_code' => 'IMP-001']);
        $this->assertDatabaseHas('assets', ['census_code' => 'IMP-002']);

        // Reimport dello stesso file: i codici censimento esistono già
        $this->post('/api/v1/imports/geojson', [
            'file' => $this->geojsonFile(),
            'area_id' => $this->area->id,
            'dry_run' => '0',
        ])->assertOk()
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.importable', 0);
    }

    public function test_import_requires_create_permission(): void
    {
        [, $viewer] = [null, \App\Models\User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $viewer->assignRole('cliente');
        $this->actingAsTenantUser($viewer);

        $this->post('/api/v1/imports/geojson', [
            'file' => $this->geojsonFile(),
            'area_id' => $this->area->id,
        ])->assertForbidden();
    }
}
