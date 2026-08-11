<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class TreeBalanceTest extends TestCase
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

    private function createTree(string $census, ?string $planted, ?string $removed = null, ?string $species = null): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => $census,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $tree = array_filter([
            'planted_on' => $planted,
            'removed_on' => $removed,
            'species' => $species,
        ], fn ($v) => $v !== null);

        if ($tree !== []) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $tree])->assertOk();
        }

        return $id;
    }

    public function test_balance_counts_initial_planted_felled_final(): void
    {
        $this->createTree('ALB-A', '2020-05-01', null, 'Tilia cordata');
        $this->createTree('ALB-B', '2023-03-10', '2024-06-15', 'Platanus x acerifolia');
        $this->createTree('ALB-C', null); // senza data impianto: vale la data di censimento (oggi)

        $data = $this->getJson('/api/v1/vta/bilancio?from=2023-01-01&to=2024-12-31')
            ->assertOk()->json('data');

        $this->assertSame(1, $data['initial_count']); // solo ALB-A esisteva al 1/1/2023
        $this->assertSame(1, $data['planted_count']); // ALB-B piantato nel periodo
        $this->assertSame(1, $data['felled_count']);  // ALB-B abbattuto nel periodo
        $this->assertSame(1, $data['final_count']);   // resta solo ALB-A
        $this->assertSame(0, $data['variation']);

        // L'identità contabile: finale = iniziale + piantati - abbattuti
        $this->assertSame(
            $data['final_count'],
            $data['initial_count'] + $data['planted_count'] - $data['felled_count'],
        );
    }

    public function test_balance_includes_trees_censused_in_period_without_planting_date(): void
    {
        $this->createTree('ALB-A', '2020-05-01');
        $this->createTree('ALB-B', '2023-03-10', '2024-06-15');
        $this->createTree('ALB-C', null);

        $from = now()->startOfYear()->toDateString();
        $to = now()->toDateString();
        $data = $this->getJson("/api/v1/vta/bilancio?from={$from}&to={$to}")
            ->assertOk()->json('data');

        $this->assertSame(1, $data['initial_count']); // ALB-A (ALB-B già abbattuto nel 2024)
        $this->assertSame(1, $data['planted_count']); // ALB-C censito oggi
        $this->assertSame(0, $data['felled_count']);
        $this->assertSame(2, $data['final_count']);
        $this->assertSame(1, $data['variation']);
    }

    public function test_balance_species_detail(): void
    {
        $this->createTree('ALB-A', '2020-05-01', null, 'Tilia cordata');
        $this->createTree('ALB-B', '2023-03-10', '2024-06-15', 'Platanus x acerifolia');

        $data = $this->getJson('/api/v1/vta/bilancio?from=2023-01-01&to=2024-12-31')
            ->assertOk()->json('data');

        $this->assertCount(1, $data['by_species']);
        $this->assertSame('Platanus x acerifolia', $data['by_species'][0]['species_label']);
        $this->assertSame(1, $data['by_species'][0]['planted']);
        $this->assertSame(1, $data['by_species'][0]['felled']);
    }

    public function test_balance_validates_date_range(): void
    {
        $this->getJson('/api/v1/vta/bilancio?from=2024-01-01&to=2023-01-01')
            ->assertUnprocessable();
        $this->getJson('/api/v1/vta/bilancio')
            ->assertUnprocessable();
    }

    public function test_removal_date_cannot_precede_planting_date(): void
    {
        $id = $this->createTree('ALB-X', '2020-05-01');

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['removed_on' => '2019-01-01'],
        ])->assertUnprocessable();
    }

    public function test_tree_change_bumps_version_and_stale_version_conflicts(): void
    {
        $id = $this->createTree('ALB-V', null);

        $this->patchJson("/api/v1/assets/{$id}", [
            'version' => 1,
            'tree' => ['species' => 'Tilia cordata'],
        ])->assertOk()->assertJsonPath('data.version', 2);

        // Il tecnico con la scheda vecchia non sovrascrive più in silenzio
        $this->patchJson("/api/v1/assets/{$id}", [
            'version' => 1,
            'tree' => ['species' => 'Acer campestre'],
        ])->assertConflict();

        $this->getJson("/api/v1/assets/{$id}")
            ->assertJsonPath('data.tree.species', 'Tilia cordata');
    }
}
