<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetTag;
use App\Support\Geometry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_global_search_finds_assets_tags_areas_and_types(): void
    {
        [$organization, $user] = $this->createTenantUser();
        $area = $this->createArea($organization, ['name' => 'Parco della Ricerca']);
        $type = $this->makeObjectType($organization, 'P', 'P103108');

        $asset = Asset::create([
            'tenant_id' => $organization->id,
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'ALB-0777',
            'geom' => Geometry::toEwkb($this->pointGeometry()),
        ]);

        AssetTag::create([
            'tenant_id' => $organization->id,
            'asset_id' => $asset->id,
            'tag_type' => 'qr',
            'uid' => 'QR-SPECIALE-42',
            'status' => 'active',
        ]);

        $this->actingAsTenantUser($user);

        // Codice censimento
        $this->getJson('/api/v1/search?q=ALB-07')
            ->assertOk()
            ->assertJsonPath('data.assets.0.census_code', 'ALB-0777');

        // UID tag esatto
        $this->getJson('/api/v1/search?q=QR-SPECIALE-42')
            ->assertOk()
            ->assertJsonPath('data.tag_match.asset.census_code', 'ALB-0777');

        // Nome area
        $this->getJson('/api/v1/search?q=Ricerca')
            ->assertOk()
            ->assertJsonPath('data.areas.0.name', 'Parco della Ricerca');

        // Catalogo per codice
        $this->getJson('/api/v1/search?q=P10310')
            ->assertOk()
            ->assertJsonPath('data.catalog_types.0.code', 'P103108');
    }

    public function test_search_is_tenant_isolated(): void
    {
        [$orgA] = $this->createTenantUser();
        $areaA = $this->createArea($orgA, ['name' => 'Giardino Segreto A']);
        $typeA = $this->makeObjectType($orgA, 'P');
        Asset::create([
            'tenant_id' => $orgA->id,
            'area_id' => $areaA->id,
            'object_type_id' => $typeA->id,
            'census_code' => 'SEGRETO-1',
            'geom' => Geometry::toEwkb($this->pointGeometry()),
        ]);

        [, $userB] = $this->createTenantUser();
        $this->actingAsTenantUser($userB);

        $response = $this->getJson('/api/v1/search?q=SEGRETO')->assertOk();
        $this->assertCount(0, $response->json('data.assets'));
        $this->assertCount(0, $response->json('data.areas'));
    }
}
