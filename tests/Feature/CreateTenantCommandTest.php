<?php

namespace Tests\Feature;

use App\Models\CatalogObjectType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTenantCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_ready_to_use_organization(): void
    {
        $this->artisan('tenant:create', [
            'name' => 'Verde Vivo srl',
            'slug' => 'verde-vivo',
            'email' => 'titolare@verdevivo.it',
        ])->assertSuccessful();

        $organization = Organization::query()->where('slug', 'verde-vivo')->firstOrFail();

        $admin = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $organization->id)
            ->where('email', 'titolare@verdevivo.it')
            ->firstOrFail();

        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        $this->assertTrue($admin->hasRole('amministratore'));

        // Catalogo ministeriale completo installato per il tenant
        $this->assertSame(387, CatalogObjectType::query()->withoutGlobalScopes()
            ->where('tenant_id', $organization->id)->count());
    }

    public function test_rejects_duplicate_slug_and_invalid_email(): void
    {
        $this->artisan('tenant:create', [
            'name' => 'Uno', 'slug' => 'doppio', 'email' => 'a@b.it',
        ])->assertSuccessful();

        $this->artisan('tenant:create', [
            'name' => 'Due', 'slug' => 'doppio', 'email' => 'c@d.it',
        ])->assertFailed();

        $this->artisan('tenant:create', [
            'name' => 'Tre', 'slug' => 'altro', 'email' => 'non-una-email',
        ])->assertFailed();

        $this->assertSame(1, Organization::query()->count());
    }
}
