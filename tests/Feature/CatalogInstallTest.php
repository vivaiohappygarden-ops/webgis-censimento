<?php

namespace Tests\Feature;

use App\Services\Catalog\CatalogInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CatalogInstallTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_md_v21_catalog_installs_completely(): void
    {
        [$organization, $user] = $this->createTenantUser();

        $counts = app(CatalogInstaller::class)->install($organization);

        $this->assertSame(4, $counts['main_types']);
        $this->assertSame(387, $counts['object_types']);

        $this->actingAsTenantUser($user);

        $response = $this->getJson('/api/v1/catalog')->assertOk();

        $this->assertSame(387, $response->json('meta.object_types_count'));
        $this->assertCount(4, $response->json('data'));

        // L'albero (P103108) esiste e alla creazione dovra' generare la scheda albero
        $tree = collect($response->json('data'))
            ->flatMap(fn ($m) => $m['sub_types'])
            ->flatMap(fn ($s) => $s['object_types'])
            ->firstWhere('code', 'P103108');

        $this->assertNotNull($tree);
        $this->assertSame('P', $tree['allowed_geometry']);
        $this->assertTrue($tree['requires_tree_record']);
    }
}
