<?php

namespace Tests\Feature;

use App\Models\CatalogObjectType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CatalogAdminTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_custom_object_type_lifecycle(): void
    {
        [$organization, $user] = $this->createTenantUser();
        $camType = $this->makeObjectType($organization, 'P', 'P103108');
        CatalogObjectType::withoutGlobalScopes()->whereKey($camType->id)->update(['is_cam' => true]);
        $this->actingAsTenantUser($user);

        // Creazione tipo personalizzato sotto un tipo secondario esistente
        $subTypeId = $camType->sub_type_id;
        $response = $this->postJson('/api/v1/catalog/object-types', [
            'sub_type_id' => $subTypeId,
            'code' => 'CUST-01',
            'name' => 'Fioriera speciale del tenant',
            'allowed_geometry' => 'P',
        ]);
        $response->assertCreated()->assertJsonPath('data.is_cam', false);
        $customId = $response->json('data.id');

        // Un tipo CAM non si può eliminare
        $this->deleteJson("/api/v1/catalog/object-types/{$camType->id}")
            ->assertUnprocessable();

        // Il tipo personalizzato senza asset sì
        $this->deleteJson("/api/v1/catalog/object-types/{$customId}")->assertNoContent();
    }

    public function test_cam_type_name_is_immutable(): void
    {
        [$organization, $user] = $this->createTenantUser();
        $camType = $this->makeObjectType($organization, 'P', 'P103108');
        CatalogObjectType::withoutGlobalScopes()->whereKey($camType->id)->update(['is_cam' => true]);
        $this->actingAsTenantUser($user);

        $this->patchJson("/api/v1/catalog/object-types/{$camType->id}", [
            'name' => 'Nome cambiato',
            'icon' => '🌲',
        ])->assertOk()
            ->assertJsonPath('data.icon', '🌲')
            ->assertJsonPath('data.name', 'Tipo oggetto di test'); // il nome CAM resta
    }
}
