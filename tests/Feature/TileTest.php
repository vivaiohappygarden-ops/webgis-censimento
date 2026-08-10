<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Support\Geometry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class TileTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_mvt_tile_contains_tenant_assets(): void
    {
        [$organization, $user] = $this->createTenantUser();
        $area = $this->createArea($organization);
        $type = $this->makeObjectType($organization, 'P');

        $lon = 9.1905;
        $lat = 45.4652;

        Asset::create([
            'tenant_id' => $organization->id,
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'TILE-1',
            'geom' => Geometry::toEwkb($this->pointGeometry($lon, $lat)),
        ]);

        $this->actingAsTenantUser($user);

        [$x, $y] = $this->tileForLonLat($lon, $lat, 15);

        $response = $this->get("/api/v1/tiles/assets/15/{$x}/{$y}");
        $response->assertOk();
        $this->assertSame('application/vnd.mapbox-vector-tile', $response->headers->get('Content-Type'));
        $this->assertNotSame('', $response->getContent());

        // Un tile lontano dall'asset è vuoto
        $this->get('/api/v1/tiles/assets/15/0/0')->assertNoContent();
    }

    /** @return array{0: int, 1: int} */
    private function tileForLonLat(float $lon, float $lat, int $zoom): array
    {
        $n = 2 ** $zoom;
        $x = (int) floor(($lon + 180.0) / 360.0 * $n);
        $latRad = deg2rad($lat);
        $y = (int) floor((1.0 - log(tan($latRad) + 1.0 / cos($latRad)) / M_PI) / 2.0 * $n);

        return [$x, $y];
    }
}
