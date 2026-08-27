<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\ImportMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Import di un file qualsiasi con mappatura delle colonne: analisi con
 * proposta, import con tipo predefinito, salta/aggiorna esistenti,
 * mappature salvate.
 */
class ImportGenericoTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->area = $this->createArea($this->organization);
        $this->actingAsTenantUser($this->user);
    }

    /** Un file "di un altro fornitore": colonne con nomi qualsiasi. */
    private function fileFornitore(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [9.19, 45.464]],
                    'properties' => ['ID_PIANTA' => 'T-001', 'SPECIE_ALB' => 'Tilia cordata', 'ALTEZZA' => '12,5', 'OSSERVAZIONI' => 'chioma sbilanciata'],
                ],
                [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [9.191, 45.465]],
                    'properties' => ['ID_PIANTA' => 'T-002', 'SPECIE_ALB' => 'Acer campestre', 'ALTEZZA' => '8', 'OSSERVAZIONI' => null],
                ],
            ],
        ];
    }

    private function analizza(array $geojson): array
    {
        return $this->post('/api/v1/imports/analizza', [
            'file' => UploadedFile::fake()->createWithContent('dati.geojson', json_encode($geojson)),
        ], ['Accept' => 'application/json'])->assertOk()->json('data');
    }

    public function test_analysis_lists_columns_preview_and_proposes_mapping(): void
    {
        $analisi = $this->analizza($this->fileFornitore());

        $this->assertSame(2, $analisi['totale']);
        $this->assertSame(2, $analisi['geometrie']['Point']);
        $nomi = array_column($analisi['colonne'], 'nome');
        $this->assertEqualsCanonicalizing(['ID_PIANTA', 'SPECIE_ALB', 'ALTEZZA', 'OSSERVAZIONI'], $nomi);
        $colonne = collect($analisi['colonne'])->keyBy('nome');
        $this->assertContains('Tilia cordata', $colonne['SPECIE_ALB']['esempi']);
        $this->assertCount(2, $analisi['anteprima']);
        // La proposta riconosce i nomi comuni
        $this->assertSame('specie', $analisi['proposta']['SPECIE_ALB']);
        $this->assertSame('altezza_m', $analisi['proposta']['ALTEZZA']);
        $this->assertSame('note', $analisi['proposta']['OSSERVAZIONI']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $analisi['token']);
    }

    public function test_proposal_recognises_our_catalog_codes_from_values(): void
    {
        $type = $this->makeObjectType($this->organization, 'P', 'P103201');
        $analisi = $this->analizza([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [9.19, 45.464]],
                'properties' => ['TIPOLOGIA' => $type->code],
            ]],
        ]);

        $this->assertSame('codice_catalogo', $analisi['proposta']['TIPOLOGIA']);
    }

    public function test_import_with_mapping_and_default_type_fills_tree_fields(): void
    {
        $albero = $this->makeObjectType($this->organization, 'P', 'P103108');
        $analisi = $this->analizza($this->fileFornitore());

        $payload = [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => [
                'ID_PIANTA' => 'codice_censimento',
                'SPECIE_ALB' => 'specie',
                'ALTEZZA' => 'altezza_m',
                'OSSERVAZIONI' => 'note',
            ],
            'default_object_type_id' => $albero->id,
        ];

        // La verifica a vuoto non scrive nulla
        $this->postJson('/api/v1/imports/generico', [...$payload, 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.importable', 2)
            ->assertJsonPath('data.imported', 0);
        $this->assertSame(0, Asset::query()->count());

        $this->postJson('/api/v1/imports/generico', [...$payload, 'dry_run' => false])
            ->assertOk()
            ->assertJsonPath('data.imported', 2);

        $tiglio = Asset::query()->with('tree')->where('census_code', 'T-001')->firstOrFail();
        $this->assertSame('Tilia cordata', $tiglio->tree->species);
        $this->assertEqualsWithDelta(12.5, (float) $tiglio->tree->height_m, 0.001);
        $this->assertSame('chioma sbilanciata', $tiglio->notes);
        $this->assertSame('shapefile_import', $tiglio->survey_method);
    }

    public function test_unknown_catalog_code_is_a_row_error_not_a_silent_fallback(): void
    {
        $default = $this->makeObjectType($this->organization, 'P');
        $analisi = $this->analizza([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [9.19, 45.464]],
                'properties' => ['COD' => 'X999999'],
            ]],
        ]);

        $report = $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['COD' => 'codice_catalogo'],
            'default_object_type_id' => $default->id,
            'dry_run' => true,
        ])->assertOk()->json('data');

        $this->assertSame(0, $report['importable']);
        $this->assertStringContainsString("X999999", $report['errors'][0]['error']);
    }

    public function test_single_part_multi_geometries_are_unwrapped(): void
    {
        $prato = $this->makeObjectType($this->organization, 'S');
        $poligono = $this->squarePolygon();
        $analisi = $this->analizza([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [$poligono['coordinates']]],
                'properties' => ['NOME' => 'prato nord'],
            ]],
        ]);

        $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => [],
            'default_object_type_id' => $prato->id,
            'dry_run' => false,
        ])->assertOk()->assertJsonPath('data.imported', 1);

        $this->assertGreaterThan(0, (float) Asset::query()->firstOrFail()->computed_area_sqm);
    }

    public function test_existing_census_codes_are_skipped_or_updated_by_choice(): void
    {
        $albero = $this->makeObjectType($this->organization, 'P', 'P103108');
        $esistenteId = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $albero->id,
            'census_code' => 'T-001',
            'geometry' => $this->pointGeometry(9.10, 45.40),
        ])->assertCreated()->json('data.id');
        $versionePrima = Asset::query()->findOrFail($esistenteId)->version;

        $analisi = $this->analizza($this->fileFornitore());
        $payload = [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ID_PIANTA' => 'codice_censimento', 'SPECIE_ALB' => 'specie', 'ALTEZZA' => 'altezza_m'],
            'default_object_type_id' => $albero->id,
        ];

        // Con "salta" il codice presente viene scartato, come nel canale CAM
        $salta = $this->postJson('/api/v1/imports/generico', [...$payload, 'esistenti' => 'salta', 'dry_run' => true])
            ->assertOk()->json('data');
        $this->assertSame(1, $salta['importable']);
        $this->assertSame(1, $salta['errors_total']);

        // Con "aggiorna" la scheda esistente prende geometria e misure nuove
        $aggiorna = $this->postJson('/api/v1/imports/generico', [...$payload, 'esistenti' => 'aggiorna', 'dry_run' => false])
            ->assertOk()->json('data');
        $this->assertSame(1, $aggiorna['imported']);
        $this->assertSame(1, $aggiorna['updated']);

        $aggiornato = Asset::query()->with('tree')->findOrFail($esistenteId);
        $this->assertSame('Tilia cordata', $aggiornato->tree->species);
        $this->assertGreaterThan($versionePrima, $aggiornato->version);
        // L'elemento resta nella sua area: l'aggiornamento non lo sposta
        $this->assertSame($this->area->id, $aggiornato->area_id);
    }

    public function test_mapping_with_duplicate_destination_is_rejected(): void
    {
        $analisi = $this->analizza($this->fileFornitore());

        $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ID_PIANTA' => 'specie', 'SPECIE_ALB' => 'specie'],
            'dry_run' => true,
        ])->assertUnprocessable();
    }

    public function test_stale_or_foreign_token_is_rejected(): void
    {
        $this->postJson('/api/v1/imports/generico', [
            'file_token' => str_repeat('a', 40),
            'area_id' => $this->area->id,
            'mappatura' => [],
            'dry_run' => true,
        ])->assertUnprocessable();
    }

    public function test_saved_mappings_are_per_tenant_and_upsert_by_name(): void
    {
        $this->postJson('/api/v1/imports/mappature', [
            'name' => 'Tracciato fornitore X',
            'mapping' => ['ID_PIANTA' => 'codice_censimento'],
        ])->assertCreated();

        // Stesso nome: si aggiorna, non si duplica
        $this->postJson('/api/v1/imports/mappature', [
            'name' => 'Tracciato fornitore X',
            'mapping' => ['ID_PIANTA' => 'codice_censimento', 'SPECIE_ALB' => 'specie'],
        ])->assertCreated();

        $elenco = $this->getJson('/api/v1/imports/mappature')->assertOk()->json('data');
        $this->assertCount(1, $elenco);
        $this->assertSame('specie', $elenco[0]['mapping']['SPECIE_ALB']);

        // Un altro tenant non vede la mappatura
        [, $altroUtente] = $this->createTenantUser();
        $this->actingAsTenantUser($altroUtente);
        $this->assertCount(0, $this->getJson('/api/v1/imports/mappature')->json('data'));

        $this->actingAsTenantUser($this->user);
        $this->deleteJson('/api/v1/imports/mappature/'.$elenco[0]['id'])->assertNoContent();
        $this->assertSame(0, ImportMapping::query()->count());
    }

    public function test_nested_property_values_do_not_crash_analysis_or_import(): void
    {
        $albero = $this->makeObjectType($this->organization, 'P', 'P103108');
        $conListe = [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [9.19, 45.464]],
                'properties' => ['ID' => 'NST-1', 'TAGS' => ['filare', 'viale'], 'EXTRA' => ['a' => 1], 'H' => '10'],
            ]],
        ];

        // L'analisi non esplode e dichiara il dato composto in anteprima
        $analisi = $this->analizza($conListe);
        $this->assertSame('[dato composto]', $analisi['anteprima'][0]['TAGS']);

        // L'import con una colonna composta mappata avvisa, non crolla
        $report = $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ID' => 'codice_censimento', 'TAGS' => 'note', 'H' => 'altezza_m'],
            'default_object_type_id' => $albero->id,
            'dry_run' => false,
        ])->assertOk()->json('data');

        $this->assertSame(1, $report['imported']);
        $this->assertStringContainsString('valore composto', implode(' ', $report['warnings']));
    }

    public function test_tree_rows_with_different_filled_columns_do_not_shift_values(): void
    {
        $albero = $this->makeObjectType($this->organization, 'P', 'P103108');
        $analisi = $this->analizza([
            'type' => 'FeatureCollection',
            'features' => [
                // Riga 1: solo specie e altezza; riga 2: solo genere e diametro.
                // Con chiavi disomogenee l'insert a blocchi farebbe slittare
                // i valori di colonna
                ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [9.19, 45.464]],
                    'properties' => ['ID' => 'SHF-1', 'GEN' => null, 'SPE' => 'Tilia cordata', 'H' => '12', 'DIA' => null]],
                ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [9.191, 45.465]],
                    'properties' => ['ID' => 'SHF-2', 'GEN' => 'Acer', 'SPE' => null, 'H' => null, 'DIA' => '35']],
            ],
        ]);

        $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ID' => 'codice_censimento', 'GEN' => 'genere', 'SPE' => 'specie', 'H' => 'altezza_m', 'DIA' => 'diametro_tronco_cm'],
            'default_object_type_id' => $albero->id,
            'dry_run' => false,
        ])->assertOk()->assertJsonPath('data.imported', 2);

        $primo = Asset::query()->with('tree')->where('census_code', 'SHF-1')->firstOrFail()->tree;
        $secondo = Asset::query()->with('tree')->where('census_code', 'SHF-2')->firstOrFail()->tree;
        $this->assertSame('Tilia cordata', $primo->species);
        $this->assertEqualsWithDelta(12.0, (float) $primo->height_m, 0.001);
        $this->assertNull($primo->dbh_cm);
        $this->assertSame('Acer', $secondo->genus);
        $this->assertEqualsWithDelta(35.0, (float) $secondo->dbh_cm, 0.001);
        $this->assertNull($secondo->height_m);
        $this->assertNull($secondo->species);
    }

    public function test_update_judges_geometry_against_the_existing_type(): void
    {
        // Scheda esistente a superficie; il file porta un punto con lo stesso
        // codice: con "aggiorna" la geometria va giudicata contro il tipo
        // VERO della scheda, e la riga scartata già in verifica
        $prato = $this->makeObjectType($this->organization, 'S');
        $alberoTipo = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $prato->id,
            'census_code' => 'MIX-1',
            'geometry' => $this->squarePolygon(),
        ])->assertCreated();

        $analisi = $this->analizza([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [9.19, 45.464]],
                'properties' => ['ID' => 'MIX-1'],
            ]],
        ]);
        $payload = [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ID' => 'codice_censimento'],
            'default_object_type_id' => $alberoTipo->id,
            'esistenti' => 'aggiorna',
        ];

        $prova = $this->postJson('/api/v1/imports/generico', [...$payload, 'dry_run' => true])
            ->assertOk()->json('data');
        $this->assertSame(0, $prova['updatable']);
        $this->assertStringContainsString('non ammessa', $prova['errors'][0]['error']);

        // L'import vero dà lo stesso esito della verifica: nessun errore 500
        $this->postJson('/api/v1/imports/generico', [...$payload, 'dry_run' => false])
            ->assertOk()->assertJsonPath('data.updated', 0);
    }

    public function test_update_mode_requires_a_census_code_column(): void
    {
        $analisi = $this->analizza($this->fileFornitore());

        $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['SPECIE_ALB' => 'specie'],
            'esistenti' => 'aggiorna',
            'dry_run' => true,
        ])->assertUnprocessable();
    }

    public function test_area_boundary_code_is_skipped_like_the_cam_channel(): void
    {
        $confine = $this->makeObjectType($this->organization, 'S', 'S325500');
        $analisi = $this->analizza([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => $this->squarePolygon(),
                'properties' => ['COD' => 'S325500'],
            ]],
        ]);

        $report = $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['COD' => 'codice_catalogo'],
            'dry_run' => true,
        ])->assertOk()->json('data');

        $this->assertSame(0, $report['importable']);
        $this->assertStringContainsString('Territorio', implode(' ', $report['warnings']));
    }

    public function test_token_is_burned_after_a_real_import(): void
    {
        $albero = $this->makeObjectType($this->organization, 'P', 'P103108');
        $analisi = $this->analizza($this->fileFornitore());
        $payload = [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ID_PIANTA' => 'codice_censimento'],
            'default_object_type_id' => $albero->id,
            'dry_run' => false,
        ];

        $this->postJson('/api/v1/imports/generico', $payload)->assertOk();
        // Un secondo clic sullo stesso gettone non duplica: l'analisi è consumata
        $this->postJson('/api/v1/imports/generico', $payload)->assertUnprocessable();
        $this->assertSame(2, Asset::query()->count());
    }

    public function test_saved_mapping_rejects_unknown_destinations(): void
    {
        $this->postJson('/api/v1/imports/mappature', [
            'name' => 'Storta',
            'mapping' => ['COL' => 'destinazione_inventata'],
        ])->assertUnprocessable();
    }

    public function test_any_shapefile_roundtrip_with_custom_columns(): void
    {
        if (! (new \App\Services\Export\CamExporter)->ogr2ogrAvailable()) {
            $this->markTestSkipped('ogr2ogr non disponibile.');
        }

        // Si costruisce un vero shapefile con colonne "di un altro fornitore"
        $dir = sys_get_temp_dir().'/shp-generico-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        file_put_contents("{$dir}/sorgente.geojson", json_encode($this->fileFornitore()));
        (new \Symfony\Component\Process\Process([
            'ogr2ogr', '-f', 'ESRI Shapefile', "{$dir}/dati.shp", "{$dir}/sorgente.geojson",
        ]))->setTimeout(60)->mustRun();

        $zipPath = "{$dir}/dati.zip";
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach (['shp', 'shx', 'dbf', 'prj'] as $ext) {
            $zip->addFile("{$dir}/dati.{$ext}", "dati.{$ext}");
        }
        $zip->close();

        $albero = $this->makeObjectType($this->organization, 'P', 'P103108');
        $analisi = $this->post('/api/v1/imports/analizza', [
            'file' => new UploadedFile($zipPath, 'dati.zip', 'application/zip', null, true),
        ], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertContains('ID_PIANTA', array_column($analisi['colonne'], 'nome'));

        $this->postJson('/api/v1/imports/generico', [
            'file_token' => $analisi['token'],
            'area_id' => $this->area->id,
            'mappatura' => ['ID_PIANTA' => 'codice_censimento', 'SPECIE_ALB' => 'specie', 'ALTEZZA' => 'altezza_m'],
            'default_object_type_id' => $albero->id,
            'dry_run' => false,
        ])->assertOk()->assertJsonPath('data.imported', 2);

        $this->assertSame('Tilia cordata', Asset::query()->with('tree')
            ->where('census_code', 'T-001')->firstOrFail()->tree->species);

        foreach (glob("{$dir}/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
