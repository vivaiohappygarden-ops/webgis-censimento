<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_offline_upload_with_client_id_is_replay_safe(): void
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

        $clientId = (string) Str::uuid();
        $upload = fn () => $this->post("/api/v1/assets/{$assetId}/photos", [
            'photo' => UploadedFile::fake()->image('campo.jpg', 800, 600),
            'category' => 'census',
            'client_id' => $clientId,
        ], ['Accept' => 'application/json']);

        // Primo invio: la foto nasce con l'UUID del dispositivo
        $upload()->assertCreated()->assertJsonPath('data.id', $clientId);

        // Retry dopo timeout: nessun doppione, si risponde con la foto esistente
        $upload()->assertOk()
            ->assertJsonPath('data.id', $clientId)
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, Photo::withoutGlobalScopes()->count());
        $this->getJson("/api/v1/assets/{$assetId}")->assertJsonCount(1, 'data.photos');
    }

    public function test_client_id_bound_to_asset_and_capture_metadata(): void
    {
        Storage::fake('local');

        [$organization, $user] = $this->createTenantUser();
        $area = $this->createArea($organization);
        $type = $this->makeObjectType($organization, 'P');
        $this->actingAsTenantUser($user);

        $makeAsset = fn (string $code) => $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => $code,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');
        $first = $makeAsset('PH-1');
        $second = $makeAsset('PH-2');

        $clientId = (string) Str::uuid();

        // Data di scatto e posizione GPS esplicite (la compressione elimina gli EXIF)
        $this->post("/api/v1/assets/{$first}/photos", [
            'photo' => UploadedFile::fake()->image('campo.jpg'),
            'client_id' => $clientId,
            'taken_at' => '2026-08-11T07:15:00+02:00',
            'lat' => 45.4652,
            'lon' => 9.1905,
        ], ['Accept' => 'application/json'])->assertCreated();

        $photo = Photo::withoutGlobalScopes()->findOrFail($clientId);
        $this->assertSame('2026-08-11 05:15:00', $photo->taken_at->utc()->toDateTimeString());
        $this->assertNotNull($photo->getRawOriginal('geom'));

        // Stesso client_id su un altro elemento: rifiutato, non replay
        $this->post("/api/v1/assets/{$second}/photos", [
            'photo' => UploadedFile::fake()->image('altro.jpg'),
            'client_id' => $clientId,
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame(1, Photo::withoutGlobalScopes()->count());
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
