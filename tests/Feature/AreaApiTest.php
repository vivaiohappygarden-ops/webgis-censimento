<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Locality;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class AreaApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Locality $locality;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();

        $client = Client::create([
            'tenant_id' => $this->organization->id, 'name' => 'Cliente', 'client_type' => 'public',
        ]);
        $site = Site::create([
            'tenant_id' => $this->organization->id, 'client_id' => $client->id, 'name' => 'Sede',
        ]);
        $this->locality = Locality::create([
            'tenant_id' => $this->organization->id, 'site_id' => $site->id, 'name' => 'Localita',
        ]);

        $this->actingAsTenantUser($this->user);
    }

    public function test_area_is_created_with_computed_measures(): void
    {
        $response = $this->postJson('/api/v1/areas', [
            'locality_id' => $this->locality->id,
            'code' => 'AREA-01',
            'name' => 'Parco test',
            'geometry' => $this->squarePolygon(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Parco test')
            ->assertJsonPath('data.geom_geojson.type', 'MultiPolygon');

        $this->assertGreaterThan(0, (float) $response->json('data.computed_area_sqm'));
        $this->assertGreaterThan(0, (float) $response->json('data.computed_perimeter_m'));
    }

    public function test_point_geometry_is_rejected_for_areas(): void
    {
        $this->postJson('/api/v1/areas', [
            'locality_id' => $this->locality->id,
            'name' => 'Area invalida',
            'geometry' => $this->pointGeometry(),
        ])->assertUnprocessable()->assertJsonValidationErrors('geometry.type');
    }

    public function test_parent_area_of_another_tenant_is_rejected(): void
    {
        [$otherOrg] = $this->createTenantUser();
        $foreignArea = $this->createArea($otherOrg);

        $this->postJson('/api/v1/areas', [
            'locality_id' => $this->locality->id,
            'name' => 'Sottoarea abusiva',
            'parent_id' => $foreignArea->id,
            'geometry' => $this->squarePolygon(),
        ])->assertNotFound();
    }

    public function test_empty_geometry_is_rejected(): void
    {
        $this->postJson('/api/v1/areas', [
            'locality_id' => $this->locality->id,
            'name' => 'Area vuota',
            'geometry' => ['type' => 'Polygon', 'coordinates' => []],
        ])->assertUnprocessable();
    }

    public function test_valid_to_before_valid_from_is_rejected(): void
    {
        $this->postJson('/api/v1/areas', [
            'locality_id' => $this->locality->id,
            'name' => 'Area con date errate',
            'valid_to' => now()->subYear()->toDateString(),
            'geometry' => $this->squarePolygon(),
        ])->assertUnprocessable()->assertJsonValidationErrors('valid_to');
    }

    public function test_invalid_polygon_is_rejected(): void
    {
        // Poligono a farfalla (auto-intersecante)
        $this->postJson('/api/v1/areas', [
            'locality_id' => $this->locality->id,
            'name' => 'Farfalla',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [0, 0], [1, 1], [1, 0], [0, 1], [0, 0],
                ]],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('geometry');
    }
}
