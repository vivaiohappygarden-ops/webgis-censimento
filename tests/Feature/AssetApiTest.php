<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\CatalogObjectType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Area $area;

    private CatalogObjectType $pointType;

    private CatalogObjectType $surfaceType;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->area = $this->createArea($this->organization);
        $this->pointType = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->surfaceType = $this->makeObjectType($this->organization, 'S', 'S101016');

        $this->actingAsTenantUser($this->user);
    }

    public function test_point_asset_can_be_created(): void
    {
        \App\Models\CustomField::create([
            'tenant_id' => $this->organization->id,
            'object_type_id' => $this->pointType->id,
            'key' => 'specie',
            'label' => 'Specie',
            'field_type' => 'text',
        ]);

        $response = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->pointType->id,
            'census_code' => 'ALB-0001',
            'survey_method' => 'gps',
            'gps_accuracy_m' => 2.5,
            'geometry' => $this->pointGeometry(),
            'attributes' => ['specie' => 'Tilia cordata'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.census_code', 'ALB-0001')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.geom_geojson.type', 'Point')
            ->assertJsonPath('data.object_type.code', 'P103108');

        $this->assertNull($response->json('data.computed_area_sqm'));
    }

    public function test_surface_asset_computes_area_from_geometry(): void
    {
        $response = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->surfaceType->id,
            'census_code' => 'PRT-0001',
            'geometry' => $this->squarePolygon(),
        ]);

        $response->assertCreated();

        $area = (float) $response->json('data.computed_area_sqm');
        // Quadrato ~0,004° x 0,0025° a Milano: ~312 m x ~278 m ≈ 87.000 mq
        $this->assertGreaterThan(50_000, $area);
        $this->assertLessThan(150_000, $area);
    }

    public function test_geometry_type_must_match_catalog(): void
    {
        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->pointType->id,
            'geometry' => $this->squarePolygon(),
        ])->assertUnprocessable()->assertJsonValidationErrors('geometry');
    }

    public function test_update_bumps_version_and_snapshots_previous_state(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->pointType->id,
            'geometry' => $this->pointGeometry(),
            'notes' => 'prima nota',
        ])->json('data.id');

        $this->patchJson("/api/v1/assets/{$id}", ['notes' => 'nota aggiornata'])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.notes', 'nota aggiornata');

        $this->assertDatabaseHas('asset_versions', ['asset_id' => $id, 'version' => 1]);
    }

    public function test_stale_version_is_rejected(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->pointType->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $this->patchJson("/api/v1/assets/{$id}", ['notes' => 'a', 'version' => 1])->assertOk();
        $this->patchJson("/api/v1/assets/{$id}", ['notes' => 'b', 'version' => 1])->assertConflict();
    }

    public function test_assets_can_be_filtered_by_bbox(): void
    {
        foreach ([[9.1905, 45.4652], [9.30, 45.50]] as $i => $coords) {
            $this->postJson('/api/v1/assets', [
                'area_id' => $this->area->id,
                'object_type_id' => $this->pointType->id,
                'census_code' => 'BBOX-'.$i,
                'geometry' => ['type' => 'Point', 'coordinates' => $coords],
            ])->assertCreated();
        }

        $response = $this->getJson('/api/v1/assets?bbox=9.18,45.46,9.20,45.47')->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('BBOX-0', $response->json('data.0.census_code'));
    }

    public function test_duplicate_census_code_is_rejected_as_validation_error(): void
    {
        $payload = [
            'area_id' => $this->area->id,
            'object_type_id' => $this->pointType->id,
            'census_code' => 'DUP-001',
            'geometry' => $this->pointGeometry(),
        ];

        $this->postJson('/api/v1/assets', $payload)->assertCreated();
        $this->postJson('/api/v1/assets', [...$payload, 'geometry' => $this->pointGeometry(9.1910, 45.4655)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('census_code');
    }

    public function test_changing_type_with_incompatible_existing_geometry_is_rejected(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->pointType->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        // Da albero (punto) a prato (superficie) senza fornire una nuova geometria
        $this->patchJson("/api/v1/assets/{$id}", ['object_type_id' => $this->surfaceType->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('geometry');
    }

    public function test_no_op_update_does_not_bump_version(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->surfaceType->id,
            'geometry' => $this->squarePolygon(),
            'notes' => 'stessa nota',
        ])->json('data.id');

        // Update senza modifiche reali su un asset AREALE (colonne generate valorizzate):
        // il trigger non deve incrementare la versione né creare snapshot spuri
        $this->patchJson("/api/v1/assets/{$id}", ['notes' => 'stessa nota'])
            ->assertOk()
            ->assertJsonPath('data.version', 1);

        $this->assertDatabaseMissing('asset_versions', ['asset_id' => $id]);
    }

    public function test_out_of_range_coordinates_are_rejected(): void
    {
        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->pointType->id,
            // Metri Web Mercator passati per errore come lon/lat
            'geometry' => ['type' => 'Point', 'coordinates' => [1020000, 5690000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('geometry');
    }

    public function test_soft_deleted_asset_disappears_from_list(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->pointType->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $this->deleteJson("/api/v1/assets/{$id}")->assertNoContent();
        $this->getJson("/api/v1/assets/{$id}")->assertNotFound();
        $this->assertSoftDeleted('assets', ['id' => $id]);
    }
}
