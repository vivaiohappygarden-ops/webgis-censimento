<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class TerritoryApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_full_territory_chain_via_api(): void
    {
        [, $user] = $this->createTenantUser();
        $this->actingAsTenantUser($user);

        $clientId = $this->postJson('/api/v1/clients', [
            'name' => 'Comune di Prova',
            'client_type' => 'public',
            'code' => 'COMPRO',
        ])->assertCreated()->json('data.id');

        $siteId = $this->postJson('/api/v1/sites', [
            'client_id' => $clientId,
            'name' => 'Territorio comunale',
            'municipality' => 'Prova',
            'province' => 'PR',
            'istat_code' => '099999',
        ])->assertCreated()->json('data.id');

        $localityId = $this->postJson('/api/v1/localities', [
            'site_id' => $siteId,
            'name' => 'Quartiere Nord',
            'code' => 'Q1',
        ])->assertCreated()->json('data.id');

        // Il cliente con sedi non si elimina
        $this->deleteJson("/api/v1/clients/{$clientId}")->assertUnprocessable();

        // La catena si vede nell'indice
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonPath('data.0.sites_count', 1);
        $this->getJson("/api/v1/localities?site_id={$siteId}")
            ->assertOk()->assertJsonPath('data.0.name', 'Quartiere Nord');

        // Eliminazione in ordine inverso
        $this->deleteJson("/api/v1/localities/{$localityId}")->assertNoContent();
        $this->deleteJson("/api/v1/sites/{$siteId}")->assertNoContent();
        $this->deleteJson("/api/v1/clients/{$clientId}")->assertNoContent();
    }

    public function test_cliente_role_cannot_manage_territory(): void
    {
        [, $user] = $this->createTenantUser(role: 'cliente');
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/clients', ['name' => 'Abusivo'])->assertForbidden();
    }
}
