<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_operatore_can_create_but_not_delete_assets(): void
    {
        [$organization, $user] = $this->createTenantUser(role: 'operatore');
        $area = $this->createArea($organization);
        $type = $this->makeObjectType($organization, 'P');

        $this->actingAsTenantUser($user);

        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/assets/{$id}")->assertForbidden();
    }

    public function test_cliente_is_read_only(): void
    {
        [$organization, $user] = $this->createTenantUser(role: 'cliente');
        $area = $this->createArea($organization);
        $type = $this->makeObjectType($organization, 'P');

        $this->actingAsTenantUser($user);

        $this->getJson('/api/v1/assets')->assertOk();
        $this->getJson('/api/v1/areas')->assertOk();

        $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertForbidden();

        // Il ruolo cliente non ha catalog.view
        $this->getJson('/api/v1/catalog')->assertForbidden();
    }
}
