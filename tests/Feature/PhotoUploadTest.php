<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PhotoUploadTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_photo_can_be_uploaded_and_served(): void
    {
        Storage::fake('local');

        [$organization, $user] = $this->createTenantUser();
        $area = $this->createArea($organization);
        $type = $this->makeObjectType($organization, 'P');
        $this->actingAsTenantUser($user);

        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $response = $this->post("/api/v1/assets/{$assetId}/photos", [
            'photo' => UploadedFile::fake()->image('albero.jpg', 800, 600),
            'category' => 'census',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.category', 'census')
            ->assertJsonPath('data.original_filename', 'albero.jpg');

        $photo = Photo::withoutGlobalScopes()->firstOrFail();
        Storage::disk('local')->assertExists($photo->s3_key);

        // Il file viene servito dall'endpoint autenticato
        $this->get($response->json('data.url'))->assertOk();

        // La scheda asset include la foto
        $this->getJson("/api/v1/assets/{$assetId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.photos');
    }

    public function test_read_only_role_cannot_upload_photos(): void
    {
        Storage::fake('local');

        [$organization, $admin] = $this->createTenantUser();
        $area = $this->createArea($organization);
        $type = $this->makeObjectType($organization, 'P');
        $this->actingAsTenantUser($admin);

        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $viewer = \App\Models\User::factory()->create(['tenant_id' => $organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        $viewer->assignRole('cliente');
        $this->actingAsTenantUser($viewer);

        $this->post("/api/v1/assets/{$assetId}/photos", [
            'photo' => UploadedFile::fake()->image('vietata.jpg'),
        ])->assertForbidden();
    }

    public function test_non_image_files_are_rejected(): void
    {
        Storage::fake('local');

        [$organization, $user] = $this->createTenantUser();
        $area = $this->createArea($organization);
        $type = $this->makeObjectType($organization, 'P');
        $this->actingAsTenantUser($user);

        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $this->postJson("/api/v1/assets/{$assetId}/photos", [
            'photo' => UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream'),
        ])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }
}
