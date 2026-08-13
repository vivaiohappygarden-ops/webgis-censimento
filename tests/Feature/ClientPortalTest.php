<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Issue;
use App\Models\Locality;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class ClientPortalTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->admin] = $this->createTenantUser();
    }

    /** Cliente con sede, località, area e un utente del portale agganciato. */
    private function makeClientWorld(string $name): array
    {
        $client = Client::create(['tenant_id' => $this->organization->id, 'name' => $name, 'client_type' => 'public']);
        $site = Site::create(['tenant_id' => $this->organization->id, 'client_id' => $client->id, 'name' => "Sede {$name}"]);
        $locality = Locality::create(['tenant_id' => $this->organization->id, 'site_id' => $site->id, 'name' => "Zona {$name}"]);
        $area = $this->createArea($this->organization, ['locality_id' => $locality->id, 'name' => "Parco {$name}"]);

        $user = User::factory()->create(['tenant_id' => $this->organization->id, 'client_id' => $client->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $user->assignRole('cliente');

        return [$client, $area, $user];
    }

    public function test_each_client_sees_only_its_own_territory(): void
    {
        [, $areaA, $userA] = $this->makeClientWorld('Girasoli');
        [, $areaB, $userB] = $this->makeClientWorld('Ortensie');

        // Un lavoro completato e una segnalazione per il cliente A
        $this->actingAsTenantUser($this->admin);
        $order = WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => WorkOrder::nextCode($this->organization->id),
            'title' => 'Potatura siepi ingresso',
            'status' => 'completed',
            'completed_at' => now(),
            'area_id' => $areaA->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $issueId = $this->postJson('/api/v1/issues', [
            'description' => 'Recinzione divelta lato nord.',
            'severity' => 'high',
            'area_id' => $areaA->id,
        ])->assertCreated()->json('data.id');

        // Il cliente A vede il suo mondo
        $this->actingAsTenantUser($userA);
        $overview = $this->getJson('/api/v1/portal/overview')->assertOk()->json();
        $this->assertTrue($overview['linked']);
        $this->assertSame('Girasoli', $overview['client']['name']);
        $this->assertSame(1, $overview['counts']['areas']);
        $this->assertSame(1, $overview['counts']['completed_orders']);
        $this->assertSame(1, $overview['counts']['open_issues']);
        $this->assertSame($order->code, $overview['orders'][0]['code']);
        $this->assertSame('Parco Girasoli', $overview['areas'][0]['name']);
        // E niente valori economici nel payload
        $this->assertStringNotContainsString('amount', json_encode($overview));

        // Il cliente B non vede nulla del cliente A
        $this->actingAsTenantUser($userB);
        $overview = $this->getJson('/api/v1/portal/overview')->assertOk()->json();
        $this->assertSame('Ortensie', $overview['client']['name']);
        $this->assertSame(1, $overview['counts']['areas']);
        $this->assertSame(0, $overview['counts']['completed_orders']);
        $this->assertSame(0, $overview['counts']['open_issues']);
        $this->assertSame([], $overview['orders']);
        $this->assertSame('Parco Ortensie', $overview['areas'][0]['name']);

        $this->assertNotNull(Issue::query()->find($issueId));
    }

    public function test_unlinked_portal_user_gets_a_clear_message(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $user->assignRole('cliente');

        $this->actingAsTenantUser($user);
        $this->getJson('/api/v1/portal/overview')->assertOk()
            ->assertJsonPath('linked', false);
        // La pagina del portale resta comunque raggiungibile
        $this->get('/portale')->assertOk();
    }

    public function test_portal_requires_its_permission(): void
    {
        $bare = User::factory()->create(['tenant_id' => $this->organization->id]);
        $this->actingAsTenantUser($bare);

        $this->getJson('/api/v1/portal/overview')->assertForbidden();
        $this->get('/portale')->assertForbidden();
    }
}
