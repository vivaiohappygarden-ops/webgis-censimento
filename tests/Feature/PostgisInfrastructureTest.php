<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgisInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgis_is_available(): void
    {
        $row = DB::selectOne('SELECT PostGIS_Version() AS v');
        $this->assertNotNull($row->v);
    }

    public function test_italian_metric_srid_is_known(): void
    {
        $row = DB::selectOne('SELECT count(*) AS n FROM spatial_ref_sys WHERE srid IN (7791, 7792, 7793, 7794)');
        $this->assertSame(4, (int) $row->n);
    }

    public function test_core_tables_exist(): void
    {
        foreach ([
            'organizations', 'users', 'roles', 'permissions', 'clients', 'contracts',
            'sites', 'localities', 'areas', 'catalog_main_types', 'catalog_sub_types',
            'catalog_object_types', 'custom_fields', 'assets', 'asset_versions',
            'asset_tags', 'photos', 'documents', 'audit_logs',
        ] as $table) {
            $this->assertTrue(
                DB::getSchemaBuilder()->hasTable($table),
                "Tabella mancante: {$table}"
            );
        }
    }
}
