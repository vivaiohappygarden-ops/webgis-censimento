<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Support\Geometry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_users_only_see_their_organization_data(): void
    {
        [$orgA] = $this->createTenantUser();
        $areaA = $this->createArea($orgA);
        $typeA = $this->makeObjectType($orgA, 'P');

        $assetA = Asset::create([
            'tenant_id' => $orgA->id,
            'area_id' => $areaA->id,
            'object_type_id' => $typeA->id,
            'census_code' => 'SEGRETO-A',
            'geom' => Geometry::toEwkb($this->pointGeometry()),
        ]);

        [, $userB] = $this->createTenantUser();
        $this->actingAsTenantUser($userB);

        $list = $this->getJson('/api/v1/assets')->assertOk();
        $this->assertSame(0, $list->json('total'));

        $this->getJson("/api/v1/assets/{$assetA->id}")->assertNotFound();
        $this->getJson('/api/v1/areas')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('meta.object_types_count', 0);
    }
}
