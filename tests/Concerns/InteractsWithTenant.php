<?php

namespace Tests\Concerns;

use App\Models\Area;
use App\Models\CatalogMainType;
use App\Models\CatalogObjectType;
use App\Models\CatalogSubType;
use App\Models\Client;
use App\Models\Locality;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Geometry;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

trait InteractsWithTenant
{
    /** @return array{0: Organization, 1: User} */
    protected function createTenantUser(array $userAttributes = [], string $role = 'amministratore'): array
    {
        $organization = Organization::factory()->create();
        app(TenantProvisioner::class)->provisionRoles($organization);

        $user = User::factory()->create(['tenant_id' => $organization->id, ...$userAttributes]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        $user->assignRole($role);

        return [$organization, $user];
    }

    protected function actingAsTenantUser(User $user): void
    {
        Sanctum::actingAs($user);
    }

    protected function createArea(Organization $organization, array $attributes = []): Area
    {
        $client = Client::create([
            'tenant_id' => $organization->id, 'name' => 'Cliente Test', 'client_type' => 'private',
        ]);
        $site = Site::create([
            'tenant_id' => $organization->id, 'client_id' => $client->id, 'name' => 'Sede Test',
        ]);
        $locality = Locality::create([
            'tenant_id' => $organization->id, 'site_id' => $site->id, 'name' => 'Localita Test',
        ]);

        return Area::create([
            'tenant_id' => $organization->id,
            'locality_id' => $locality->id,
            'name' => 'Area Test',
            'geom' => Geometry::toEwkb($this->squarePolygon(), forceMultiPolygon: true),
            ...$attributes,
        ]);
    }

    protected function makeObjectType(Organization $organization, string $geo = 'P', ?string $code = null): CatalogObjectType
    {
        $main = CatalogMainType::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $organization->id, 'code' => '1'],
            ['name' => 'Vegetazione'],
        );
        $sub = CatalogSubType::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $organization->id, 'main_type_id' => $main->id, 'code' => '03'],
            ['name' => 'PIANTA'],
        );

        return CatalogObjectType::create([
            'tenant_id' => $organization->id,
            'sub_type_id' => $sub->id,
            'code' => $code ?? $geo.'103'.fake()->unique()->numberBetween(100, 999),
            'name' => 'Tipo oggetto di test',
            'allowed_geometry' => $geo,
            'cam_layer' => $geo.'1',
            'is_cam' => true,
        ]);
    }

    protected function squarePolygon(): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[
                [9.1890, 45.4640], [9.1930, 45.4640], [9.1930, 45.4665], [9.1890, 45.4665], [9.1890, 45.4640],
            ]],
        ];
    }

    protected function pointGeometry(float $lon = 9.1905, float $lat = 45.4652): array
    {
        return ['type' => 'Point', 'coordinates' => [$lon, $lat]];
    }
}
