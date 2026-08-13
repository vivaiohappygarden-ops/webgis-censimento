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

    public function test_cliente_sees_only_the_portal(): void
    {
        [$organization, $user] = $this->createTenantUser(role: 'cliente');
        $area = $this->createArea($organization);
        $type = $this->makeObjectType($organization, 'P');

        $this->actingAsTenantUser($user);

        // Con più clienti nello stesso tenant la lettura generale del
        // censimento mostrerebbe i dati degli altri: il cliente ha solo
        // il suo portale
        $this->getJson('/api/v1/portal/overview')->assertOk();
        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->getJson('/api/v1/areas')->assertForbidden();

        $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertForbidden();

        $this->getJson('/api/v1/catalog')->assertForbidden();
    }
}
