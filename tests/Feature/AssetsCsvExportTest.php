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

        $row = str_getcsv($lines[1], ';', '"', '');
        $this->assertSame('ALB-CSV-1', $row[0]);
        $this->assertSame('P103108', $row[1]);
        $this->assertSame('Cliente Test', $row[4]);
        $this->assertSame('Parco Export', $row[5]);
        $this->assertSame('attivo', $row[7]);
        $this->assertSame('10/05/2026', $row[8]);
        // La specie non raddoppia il genere; i decimali usano la virgola
        $this->assertSame('Tilia cordata', $row[9]);
        $this->assertSame('12,5', $row[11]);
        $this->assertStringContainsString('è accentata', $row[16]);
    }

    public function test_cells_starting_like_formulas_are_neutralized_and_quotes_survive(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => '=HYPERLINK("http://x";"clic")',
            'geometry' => $this->pointGeometry(),
            'notes' => 'percorso "C:\\rilievi\\" con virgolette',
        ])->assertCreated()->json('data.id');

        $csv = $this->download();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $row = str_getcsv($lines[1], ';', '"', '');

        // La cella-formula esce disinnescata con l'apostrofo davanti
        $this->assertStringStartsWith("'=HYPERLINK", $row[0]);
        // Le virgolette con backslash sopravvivono al giro (RFC 4180)
        $this->assertSame('percorso "C:\\rilievi\\" con virgolette', $row[16]);
    }

    public function test_export_beyond_one_chunk_loses_nothing(): void
    {
        // 501 righe con codici in ordine alfabetico OPPOSTO a quello di
        // creazione: se i blocchi usassero un ordinamento diverso dal
        // cursore, qui salterebbero o raddoppierebbero delle righe
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            INSERT INTO assets (id, tenant_id, area_id, object_type_id, census_code, status, geom, created_at, updated_at)
            SELECT gen_random_uuid(), :tenant, :area, :type,
                   'BULK-'||lpad((600 - i)::text, 4, '0'), 'active',
                   ST_SetSRID(ST_MakePoint(9.19, 45.46), 4326), now(), now()
            FROM generate_series(1, 501) AS i
        SQL, [
            'tenant' => $this->organization->id,
            'area' => $this->area->id,
            'type' => $this->treeType->id,
        ]);

        $csv = $this->download('?q=BULK-');
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        array_shift($lines);

        $codes = array_map(fn ($line) => str_getcsv($line, ';', '"', '')[0], $lines);
        $this->assertCount(501, $codes);
        $this->assertCount(501, array_unique($codes), 'righe duplicate nell\'export');
    }

    public function test_dismissed_status_is_translated(): void
    {
        $id = $this->makeAsset('ALB-DISMESSO');
        $this->patchJson("/api/v1/assets/{$id}", ['status' => 'dismissed', 'version' => 1])->assertOk();

        $csv = $this->download('?status=dismissed');
        $this->assertStringContainsString(';dismesso;', $csv);
        $this->assertStringNotContainsString(';dismissed;', $csv);
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
