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

    public function test_asset_in_use_cannot_be_deleted(): void
    {
        $id = $this->createPointAsset();

        $woId = $this->postJson('/api/v1/work-orders', ['title' => 'Potatura'])->json('data.id');
        $this->postJson("/api/v1/work-orders/{$woId}/assets", ['asset_id' => $id])->assertOk();

        $response = $this->deleteJson("/api/v1/assets/{$id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('asset');

        $this->assertStringContainsString('1 ordine di lavoro', $response->json('errors.asset.0'));
        $this->assertDatabaseHas('assets', ['id' => $id, 'deleted_at' => null]);
    }

    public function test_removal_registration_aligns_status_tree_and_public_page(): void
    {
        $id = $this->createPointAsset();
        $this->postJson("/api/v1/assets/{$id}/public-page")->assertOk();

        $response = $this->postJson("/api/v1/assets/{$id}/removal", [
            'removed_on' => '2026-08-14',
            'removal_reason' => 'Classe D, schianto',
        ])->assertOk();

        $this->assertSame('removed', $response->json('data.status'));
        $this->assertNull($response->json('data.public_token'));
        $this->assertSame('Classe D, schianto', $response->json('data.removal_reason'));
        $this->assertStringStartsWith('2026-08-14', $response->json('data.valid_to'));
        // La data serve anche alla scheda albero: il bilancio arboreo legge quella
        $this->assertStringStartsWith('2026-08-14', $response->json('data.tree.removed_on'));
        $this->assertSame('Classe D, schianto', $response->json('data.tree.removal_reason'));

        // Registrato come tale nel giornale delle modifiche
        $this->assertDatabaseHas('audit_logs', ['action' => 'asset.removal_registered', 'subject_id' => $id]);
    }

    public function test_removal_can_be_cancelled(): void
    {
        $id = $this->createPointAsset();
        $this->postJson("/api/v1/assets/{$id}/removal", ['removed_on' => '2026-08-14'])->assertOk();

        $response = $this->deleteJson("/api/v1/assets/{$id}/removal")->assertOk();

        $this->assertSame('active', $response->json('data.status'));
        $this->assertNull($response->json('data.valid_to'));
        $this->assertNull($response->json('data.tree.removed_on'));
    }

    public function test_removal_before_planting_date_is_rejected(): void
    {
        $id = $this->createPointAsset();
        $this->patchJson("/api/v1/assets/{$id}", ['tree' => ['planted_on' => '2020-03-01']])->assertOk();

        $this->postJson("/api/v1/assets/{$id}/removal", ['removed_on' => '2019-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tree.removed_on');

        $this->assertDatabaseHas('assets', ['id' => $id, 'status' => 'active']);
    }

    public function test_removed_assets_can_be_hidden_from_the_list(): void
    {
        $kept = $this->createPointAsset('ALB-VIVO');
        $felled = $this->createPointAsset('ALB-ABBATTUTO');
        $this->postJson("/api/v1/assets/{$felled}/removal", ['removed_on' => '2026-08-14'])->assertOk();

        $all = $this->getJson('/api/v1/assets')->assertOk()->json('data');
        $this->assertCount(2, $all);

        $visible = $this->getJson('/api/v1/assets?hide_removed=1')->assertOk()->json('data');
        $this->assertCount(1, $visible);
        $this->assertSame($kept, $visible[0]['id']);
    }

    public function test_assets_can_be_filtered_by_client_and_area(): void
    {
        $mine = $this->createPointAsset('ALB-CLIENTE-A');

        // Secondo committente, con la sua catena sede -> località -> area
        $otherArea = $this->createArea($this->organization, ['name' => 'Area committente B']);
        $otherId = $this->postJson('/api/v1/assets', [
            'area_id' => $otherArea->id,
            'object_type_id' => $this->pointType->id,
            'census_code' => 'ALB-CLIENTE-B',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $clientId = $this->area->locality->site->client_id;

        $byClient = $this->getJson("/api/v1/assets?client_id={$clientId}")->assertOk()->json('data');
        $this->assertCount(1, $byClient);
        $this->assertSame($mine, $byClient[0]['id']);
        // Il committente arriva già nell'elenco: si legge senza aprire la scheda
        $this->assertSame('Cliente Test', $byClient[0]['area']['locality']['site']['client']['name']);

        $byArea = $this->getJson("/api/v1/assets?area_id={$otherArea->id}")->assertOk()->json('data');
        $this->assertCount(1, $byArea);
        $this->assertSame($otherId, $byArea[0]['id']);

        // Committente e area incoerenti tra loro: nessun risultato, non un errore
        $this->getJson("/api/v1/assets?client_id={$clientId}&area_id={$otherArea->id}")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_areas_can_be_filtered_by_client(): void
    {
        $otherArea = $this->createArea($this->organization, ['name' => 'Area committente B']);
        $clientId = $otherArea->locality->site->client_id;

        $areas = $this->getJson("/api/v1/areas?client_id={$clientId}")->assertOk()->json('data');

        $this->assertCount(1, $areas);
        $this->assertSame($otherArea->id, $areas[0]['id']);
    }

    private function createPointAsset(?string $code = null): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->pointType->id,
            'census_code' => $code,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }
}
