<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class ProtectedTreesTest extends TestCase
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
        $this->area = $this->createArea($this->organization);
        $this->treeType = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function createTree(string $census): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => $census,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');
    }

    public function test_monumental_and_dedicated_trees_appear_in_registry(): void
    {
        $monumental = $this->createTree('ALB-MON');
        $dedicated = $this->createTree('ALB-DED');
        $this->createTree('ALB-ORD'); // ordinario: non deve comparire

        $this->patchJson("/api/v1/assets/{$monumental}", [
            'tree' => [
                'species' => 'Quercus robur',
                'is_monumental' => true,
                'monumental_ref' => 'AMI/03/000123',
            ],
        ])->assertOk();

        $this->patchJson("/api/v1/assets/{$dedicated}", [
            'tree' => [
                'species' => 'Prunus avium',
                'is_dedicated' => true,
                'dedicated_to' => ['name' => 'Maria Rossi', 'occasion' => 'nuova nata', 'date' => '2026-03-21'],
            ],
        ])->assertOk();

        $rows = $this->getJson('/api/v1/vta/tutelati')->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $codes = array_column($rows, 'census_code');
        $this->assertContains('ALB-MON', $codes);
        $this->assertContains('ALB-DED', $codes);
        $this->assertNotContains('ALB-ORD', $codes);

        $ded = collect($rows)->firstWhere('census_code', 'ALB-DED');
        $this->assertTrue($ded['is_dedicated']);
        $this->assertSame('Maria Rossi', $ded['dedicated_to']['name']);
        $this->assertSame('nuova nata', $ded['dedicated_to']['occasion']);

        $mon = collect($rows)->firstWhere('census_code', 'ALB-MON');
        $this->assertTrue($mon['is_monumental']);
        $this->assertSame('AMI/03/000123', $mon['monumental_ref']);
    }

    public function test_registry_is_tenant_isolated(): void
    {
        $mine = $this->createTree('ALB-MIO');
        $this->patchJson("/api/v1/assets/{$mine}", [
            'tree' => ['is_monumental' => true],
        ])->assertOk();

        // Un altro tenant con il proprio albero monumentale
        [$otherOrg, $otherUser] = $this->createTenantUser();
        $otherArea = $this->createArea($otherOrg);
        $otherType = $this->makeObjectType($otherOrg, 'P', 'P103108');
        $this->actingAsTenantUser($otherUser);
        $otherTree = $this->postJson('/api/v1/assets', [
            'area_id' => $otherArea->id,
            'object_type_id' => $otherType->id,
            'census_code' => 'ALB-ALTRUI',
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');
        $this->patchJson("/api/v1/assets/{$otherTree}", [
            'tree' => ['is_monumental' => true],
        ])->assertOk();

        $rows = $this->getJson('/api/v1/vta/tutelati')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('ALB-ALTRUI', $rows[0]['census_code']);
    }
}
