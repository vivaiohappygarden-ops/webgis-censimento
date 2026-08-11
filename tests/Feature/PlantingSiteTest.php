<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PlantingSiteTest extends TestCase
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
        $this->actingAsTenantUser($this->user);
    }

    public function test_custom_type_with_planting_site_flag_can_be_created_via_api(): void
    {
        $subTypeId = $this->makeObjectType($this->organization)->sub_type_id;

        $this->postJson('/api/v1/catalog/object-types', [
            'sub_type_id' => $subTypeId,
            'code' => 'PL001',
            'name' => 'Posto libero (sede di impianto)',
            'allowed_geometry' => 'P',
            'is_planting_site' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_planting_site', true)
            ->assertJsonPath('data.is_cam', false);
    }

    public function test_asset_with_planting_site_type_gets_record_and_can_be_updated(): void
    {
        $type = $this->makeObjectType($this->organization, 'P', 'PL001', [
            'is_cam' => false, 'is_planting_site' => true,
        ]);

        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $type->id,
            'census_code' => 'PSL-0001',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        // Il record posto libero nasce con l'asset, stato "free"
        $this->getJson("/api/v1/assets/{$assetId}")
            ->assertOk()
            ->assertJsonPath('data.planting_site.status', 'free');

        // Flusso: libero -> riservato con specie prevista e origine
        $this->patchJson("/api/v1/assets/{$assetId}", [
            'planting_site' => [
                'status' => 'reserved',
                'planned_species' => 'Acer campestre',
                'origin' => 'felling',
                'target_season' => '2026-2027',
            ],
        ])->assertOk()
            ->assertJsonPath('data.planting_site.status', 'reserved')
            ->assertJsonPath('data.planting_site.planned_species', 'Acer campestre')
            ->assertJsonPath('data.planting_site.origin', 'felling');

        // Poi: riservato -> piantumato
        $this->patchJson("/api/v1/assets/{$assetId}", [
            'planting_site' => ['status' => 'planted'],
        ])->assertOk()->assertJsonPath('data.planting_site.status', 'planted');
    }

    public function test_planting_site_rejects_invalid_status_and_origin(): void
    {
        $type = $this->makeObjectType($this->organization, 'P', 'PL001', [
            'is_cam' => false, 'is_planting_site' => true,
        ]);

        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $this->patchJson("/api/v1/assets/{$assetId}", [
            'planting_site' => ['status' => 'occupato'],
        ])->assertUnprocessable();

        $this->patchJson("/api/v1/assets/{$assetId}", [
            'planting_site' => ['origin' => 'meteorite'],
        ])->assertUnprocessable();
    }

    public function test_planting_site_change_bumps_version_and_stale_version_conflicts(): void
    {
        $type = $this->makeObjectType($this->organization, 'P', 'PL001', [
            'is_cam' => false, 'is_planting_site' => true,
        ]);

        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        // La modifica del posto libero incrementa la versione della scheda
        $this->patchJson("/api/v1/assets/{$assetId}", [
            'version' => 1,
            'planting_site' => ['status' => 'reserved'],
        ])->assertOk()->assertJsonPath('data.version', 2);

        // Un salvataggio con versione stantia viene rifiutato (409), non sovrascrive
        $this->patchJson("/api/v1/assets/{$assetId}", [
            'version' => 1,
            'planting_site' => ['status' => 'unusable'],
        ])->assertConflict();

        $this->getJson("/api/v1/assets/{$assetId}")
            ->assertJsonPath('data.planting_site.status', 'reserved');
    }

    public function test_planting_site_payload_is_ignored_for_non_site_assets(): void
    {
        $type = $this->makeObjectType($this->organization, 'P');

        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $this->patchJson("/api/v1/assets/{$assetId}", [
            'planting_site' => ['status' => 'reserved'],
        ])->assertOk()->assertJsonPath('data.planting_site', null);
    }
}
