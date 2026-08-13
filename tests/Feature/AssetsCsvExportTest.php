<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class AssetsCsvExportTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    private $treeType;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->actingAsTenantUser($this->user);
        $this->area = $this->createArea($this->organization, ['name' => 'Parco Export']);
        $this->treeType = $this->makeObjectType($this->organization, 'P', 'P103108');
    }

    private function makeAsset(string $code, array $tree = []): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => $code,
            'geometry' => $this->pointGeometry(),
            'surveyed_at' => '2026-05-10',
            'notes' => 'Nota con è accentata',
        ])->assertCreated()->json('data.id');

        if ($tree) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $tree])->assertOk();
        }

        return $id;
    }

    private function download(string $suffix = ''): string
    {
        $response = $this->get('/api/v1/exports/assets.csv'.$suffix)->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        return $response->streamedContent();
    }

    public function test_csv_contains_the_census_with_italian_conventions(): void
    {
        $this->makeAsset('ALB-CSV-1', [
            'genus' => 'Tilia', 'species' => 'Tilia cordata', 'common_name' => 'Tiglio',
            'height_m' => 12.5, 'dbh_cm' => 35,
        ]);
        $this->makeAsset('ALB-CSV-2');

        $csv = $this->download();

        // BOM per l'Excel italiano e intestazioni in italiano
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Codice;Tipo;', $csv);

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines);

        $row = str_getcsv($lines[1], ';');
        $this->assertSame('ALB-CSV-1', $row[0]);
        $this->assertSame('P103108', $row[1]);
        $this->assertSame('Parco Export', $row[4]);
        $this->assertSame('attivo', $row[6]);
        $this->assertSame('10/05/2026', $row[7]);
        // La specie non raddoppia il genere; i decimali usano la virgola
        $this->assertSame('Tilia cordata', $row[8]);
        $this->assertSame('12,5', $row[10]);
        $this->assertStringContainsString('è accentata', $row[15]);
    }

    public function test_csv_respects_filters_permissions_and_tenants(): void
    {
        $this->makeAsset('ALB-FILTRO-1');
        $this->makeAsset('PRA-FILTRO-2');

        // Il filtro di ricerca vale anche per l'export
        $filtered = $this->download('?q=ALB-FILTRO');
        $this->assertStringContainsString('ALB-FILTRO-1', $filtered);
        $this->assertStringNotContainsString('PRA-FILTRO-2', $filtered);

        // Un altro tenant scarica un censimento vuoto, non il mio
        [, $foreign] = $this->createTenantUser();
        $this->actingAsTenantUser($foreign);
        $foreignCsv = $this->download();
        $this->assertStringNotContainsString('ALB-FILTRO-1', $foreignCsv);

        // Il cliente del portale non ha assets.view: niente export
        $portal = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $portal->assignRole('cliente');
        $this->actingAsTenantUser($portal);
        $this->get('/api/v1/exports/assets.csv')->assertForbidden();
    }
}
