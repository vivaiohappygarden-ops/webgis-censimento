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

        // Il diametro della chioma viaggia nella tessera: serve alla mappa
        // per disegnare il cerchio a dimensione reale
        \App\Models\Tree::create([
            'asset_id' => Asset::withoutGlobalScopes()->where('census_code', 'TILE-1')->firstOrFail()->id,
            'tenant_id' => $organization->id,
            'crown_diameter_m' => 8.5,
        ]);
        $conChioma = $this->get("/api/v1/tiles/assets/15/{$x}/{$y}")->assertOk()->getContent();
        $this->assertStringContainsString('chioma_m', $conChioma);
        $this->assertStringContainsString('8.5', $conChioma);

        // Un tile lontano dall'asset è vuoto
        $this->get('/api/v1/tiles/assets/15/0/0')->assertNoContent();
    }

    public function test_tiles_can_be_filtered_by_client_and_hide_removed_assets(): void
    {
        [$organization, $user] = $this->createTenantUser();
        $area = $this->createArea($organization);
        $otherArea = $this->createArea($organization, ['name' => 'Area committente B']);
        $type = $this->makeObjectType($organization, 'P');

        $lon = 9.1905;
        $lat = 45.4652;
        $make = fn (string $code, string $areaId, string $status = 'active') => Asset::create([
            'tenant_id' => $organization->id,
            'area_id' => $areaId,
            'object_type_id' => $type->id,
            'census_code' => $code,
            'status' => $status,
            'geom' => Geometry::toEwkb($this->pointGeometry($lon, $lat)),
        ]);

        $make('TILE-UNO', $area->id);
        $make('TILE-DUE', $otherArea->id);
        $make('TILE-RIMOSSO', $area->id, 'removed');

        $this->actingAsTenantUser($user);
        [$x, $y] = $this->tileForLonLat($lon, $lat, 15);
        $base = "/api/v1/tiles/assets/15/{$x}/{$y}";

        // Il MVT è binario ma i valori delle proprietà restano leggibili
        $tutti = $this->get($base)->assertOk()->getContent();
        $this->assertStringContainsString('TILE-UNO', $tutti);
        $this->assertStringContainsString('TILE-DUE', $tutti);
        $this->assertStringContainsString('TILE-RIMOSSO', $tutti);

        $clientId = $area->locality->site->client_id;
        $delCommittente = $this->get("{$base}?client_id={$clientId}")->assertOk()->getContent();
        $this->assertStringContainsString('TILE-UNO', $delCommittente);
        $this->assertStringNotContainsString('TILE-DUE', $delCommittente);

        $senzaAbbattuti = $this->get("{$base}?hide_removed=1")->assertOk()->getContent();
        $this->assertStringContainsString('TILE-UNO', $senzaAbbattuti);
        $this->assertStringNotContainsString('TILE-RIMOSSO', $senzaAbbattuti);

        $solaArea = $this->get("{$base}?area_id={$otherArea->id}")->assertOk()->getContent();
        $this->assertStringContainsString('TILE-DUE', $solaArea);
        $this->assertStringNotContainsString('TILE-UNO', $solaArea);
    }

    public function test_il_filtro_archivio_delle_tessere_segue_l_elenco(): void
    {
        [$organization, $user] = $this->createTenantUser();
        $area = $this->createArea($organization);
        $type = $this->makeObjectType($organization, 'P');

        $lon = 9.1905;
        $lat = 45.4652;
        $make = fn (string $code, string $status) => Asset::create([
            'tenant_id' => $organization->id,
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => $code,
            'status' => $status,
            'geom' => Geometry::toEwkb($this->pointGeometry($lon, $lat)),
        ]);
        $make('TILE-VIVO', 'active');
        $make('TILE-RIMOSSO', 'removed');
        $make('TILE-DISMESSO', 'dismissed');

        $this->actingAsTenantUser($user);
        [$x, $y] = $this->tileForLonLat($lon, $lat, 15);
        $base = "/api/v1/tiles/assets/15/{$x}/{$y}";

        // archivio=0: la mappa di tutti i giorni, senza abbattuti ne' dismessi
        $quotidiano = $this->get("{$base}?archivio=0")->assertOk()->getContent();
        $this->assertStringContainsString('TILE-VIVO', $quotidiano);
        $this->assertStringNotContainsString('TILE-RIMOSSO', $quotidiano);
        $this->assertStringNotContainsString('TILE-DISMESSO', $quotidiano);

        // archivio=1: solo l'archivio
        $archivio = $this->get("{$base}?archivio=1")->assertOk()->getContent();
        $this->assertStringNotContainsString('TILE-VIVO', $archivio);
        $this->assertStringContainsString('TILE-RIMOSSO', $archivio);
        $this->assertStringContainsString('TILE-DISMESSO', $archivio);

        // hide_removed conserva il vecchio significato: il dismesso resta
        $vecchio = $this->get("{$base}?hide_removed=1")->assertOk()->getContent();
        $this->assertStringContainsString('TILE-DISMESSO', $vecchio);
        $this->assertStringNotContainsString('TILE-RIMOSSO', $vecchio);
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
