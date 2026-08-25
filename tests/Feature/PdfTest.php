<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PdfTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->actingAsTenantUser($this->user);
    }

    public function test_inspection_report_pdf(): void
    {
        $area = $this->createArea($this->organization);
        $templateId = $this->postJson('/api/v1/inspection-templates', [
            'name' => 'Controllo area giochi', 'target' => 'area', 'standard_ref' => 'UNI EN 1176-7:2020',
        ])->assertCreated()->json('data.id');
        $items = $this->putJson("/api/v1/inspection-templates/{$templateId}/items", [
            'items' => [
                ['question' => 'Superfici antitrauma integre'],
                ['question' => 'Cartellonistica presente', 'answer_type' => 'ok_ko_na', 'ko_creates_nc' => false],
            ],
        ])->assertOk()->json('data.items');

        $inspectionId = $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId,
            'area_id' => $area->id,
            'answers' => [
                $items[0]['id'] => ['value' => 'ko', 'note' => 'Gomma sollevata vicino allo scivolo'],
                $items[1]['id'] => ['value' => 'na'],
            ],
        ])->assertOk()->json('data.id');

        $response = $this->get("/api/v1/inspections/{$inspectionId}/pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('verbale_ispezione_', $response->headers->get('Content-Disposition'));

        // La fotografia porta l'ordine delle domande: jsonb non conserva
        // l'ordine delle chiavi e il verbale numera le righe
        $answers = \App\Models\Inspection::query()->findOrFail($inspectionId)->answers;
        $this->assertEqualsCanonicalizing([1, 2], array_column($answers, 'sort_order'));

        // Senza alcun permesso il verbale non si scarica
        $bare = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        $this->actingAsTenantUser($bare);
        $this->get("/api/v1/inspections/{$inspectionId}/pdf")->assertForbidden();

        // E per un'altra organizzazione l'ispezione non esiste
        [, $otherUser] = $this->createTenantUser();
        $this->actingAsTenantUser($otherUser);
        $this->get("/api/v1/inspections/{$inspectionId}/pdf")->assertNotFound();
    }

    public function test_asset_sheet_pdf_with_tree_and_photo(): void
    {
        Storage::fake('local');
        $area = $this->createArea($this->organization);
        $type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'ALB-PDF-1',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$assetId}", [
            'tree' => ['genus' => 'Platanus', 'species' => 'Platanus x acerifolia', 'height_m' => 22, 'dbh_cm' => 55],
        ])->assertOk();
        // Foto grande: viene ridimensionata prima di entrare nel PDF
        $this->post("/api/v1/assets/{$assetId}/photos", [
            'photo' => UploadedFile::fake()->image('albero.jpg', 2400, 1600),
            'category' => 'census',
        ])->assertCreated();

        $response = $this->get("/api/v1/assets/{$assetId}/pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('scheda_ALB-PDF-1.pdf', $response->headers->get('Content-Disposition'));
        $this->assertLessThan(1024 * 1024, strlen($response->getContent()),
            'La scheda non deve incorporare la foto a piena risoluzione');

        // Un elemento di un'altra organizzazione resta invisibile
        [, $otherUser] = $this->createTenantUser();
        $this->actingAsTenantUser($otherUser);
        $this->get("/api/v1/assets/{$assetId}/pdf")->assertNotFound();

        // E senza alcun permesso la scheda non si scarica
        $bare = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        $this->actingAsTenantUser($bare);
        $this->get("/api/v1/assets/{$assetId}/pdf")->assertForbidden();
    }

    public function test_la_scheda_elemento_stampa_tutte_le_fotografie(): void
    {
        Storage::fake('local');
        $stampe = new \Tests\Support\RaccoglitorePdf;
        $this->app->instance(\App\Services\Pdf\PdfRenderer::class, $stampe);

        $area = $this->createArea($this->organization);
        $type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'ALB-PDF-2',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        foreach (['census', 'defect', 'other'] as $i => $categoria) {
            $this->post("/api/v1/assets/{$assetId}/photos", [
                'photo' => UploadedFile::fake()->image("foto{$i}.jpg", 320, 240),
                'category' => $categoria,
            ])->assertCreated();
        }

        $this->get("/api/v1/assets/{$assetId}/pdf")->assertOk();

        // La versione precedente stampava una sola foto "di riferimento" e
        // scartava le altre in silenzio: ora entrano tutte, con la didascalia
        $dati = $stampe->dati['pdf.asset'];
        $this->assertCount(3, $dati['foto']);
        $this->assertNull($dati['fotoNota']);
        $this->assertEqualsCanonicalizing(['censimento', 'difetto', 'altro'],
            array_column($dati['foto'], 'categoria'));

        $html = $stampe->html['pdf.asset'];
        $this->assertStringContainsString('Documentazione fotografica', $html);
        $this->assertStringContainsString('scattata il', $html);
    }

    public function test_oltre_il_tetto_la_scheda_dichiara_il_totale(): void
    {
        Storage::fake('local');
        $stampe = new \Tests\Support\RaccoglitorePdf;
        $this->app->instance(\App\Services\Pdf\PdfRenderer::class, $stampe);

        $area = $this->createArea($this->organization);
        $type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'ALB-PDF-3',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $oltre = \App\Services\Photos\FotoStampa::MASSIMO + 2;
        for ($i = 1; $i <= $oltre; $i++) {
            $this->post("/api/v1/assets/{$assetId}/photos", [
                'photo' => UploadedFile::fake()->image("foto{$i}.jpg", 60, 40),
                'category' => 'census',
                'taken_at' => sprintf('2026-07-%02d 10:00:00', $i),
            ])->assertCreated();
        }

        $this->get("/api/v1/assets/{$assetId}/pdf")->assertOk();

        $dati = $stampe->dati['pdf.asset'];
        $this->assertCount(\App\Services\Photos\FotoStampa::MASSIMO, $dati['foto']);

        // Non basta il conteggio: devono essere LE PIÙ RECENTI (spariscono
        // il 1 e il 2 luglio), stampate in ordine di scatto
        $attese = [];
        for ($i = 3; $i <= $oltre; $i++) {
            $attese[] = sprintf('%02d/07/2026', $i);
        }
        $this->assertSame($attese, array_column($dati['foto'], 'scattata'));

        $this->assertStringContainsString("risultano {$oltre} fotografie", $dati['fotoNota']);
        $this->assertStringContainsString('le più recenti', $dati['fotoNota']);
    }

    public function test_un_file_illeggibile_non_ruba_il_posto_a_una_foto_leggibile(): void
    {
        Storage::fake('local');
        $stampe = new \Tests\Support\RaccoglitorePdf;
        $this->app->instance(\App\Services\Pdf\PdfRenderer::class, $stampe);

        $area = $this->createArea($this->organization);
        $type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'ALB-PDF-4',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $oltre = \App\Services\Photos\FotoStampa::MASSIMO + 2;
        $ids = [];
        for ($i = 1; $i <= $oltre; $i++) {
            $ids[$i] = $this->post("/api/v1/assets/{$assetId}/photos", [
                'photo' => UploadedFile::fake()->image("foto{$i}.jpg", 60, 40),
                'category' => 'census',
                'taken_at' => sprintf('2026-07-%02d 10:00:00', $i),
            ])->assertCreated()->json('data.id');
        }

        // Il file della foto più recente sparisce dal disco (originale e copia)
        $rotta = \App\Models\Photo::withoutGlobalScopes()->findOrFail($ids[$oltre]);
        Storage::disk('local')->delete($rotta->s3_key);
        \App\Services\Photos\PublicPhotoCache::dimentica($rotta);

        $this->get("/api/v1/assets/{$assetId}/pdf")->assertOk();

        // Il posto non va perso: si stampa comunque il massimo, ripescando
        // la foto leggibile più vecchia rimasta fuori dal tetto
        $dati = $stampe->dati['pdf.asset'];
        $this->assertCount(\App\Services\Photos\FotoStampa::MASSIMO, $dati['foto']);
        $scattate = array_column($dati['foto'], 'scattata');
        $this->assertContains('02/07/2026', $scattate);
        $this->assertNotContains(sprintf('%02d/07/2026', $oltre), $scattate);
        $this->assertStringContainsString('Una fotografia non è stata stampata perché il file non è leggibile',
            $dati['fotoNota']);
    }

    public function test_se_il_tempo_scade_le_foto_restanti_sono_dichiarate(): void
    {
        Storage::fake('local');
        $area = $this->createArea($this->organization);
        $type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'ALB-PDF-5',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        for ($i = 0; $i < 3; $i++) {
            $this->post("/api/v1/assets/{$assetId}/photos", [
                'photo' => UploadedFile::fake()->image("foto{$i}.jpg", 60, 40),
            ])->assertCreated();
        }

        // Scadenza già passata: nessuna immagine si prepara, ma la stampa
        // esce e dichiara quante ne mancano e come recuperarle
        $esito = \App\Services\Photos\FotoStampa::perScheda($assetId, microtime(true) - 1);

        $this->assertSame([], $esito['foto']);
        $this->assertStringContainsString('3 fotografie non sono entrate per il tempo massimo', $esito['nota']);
        $this->assertStringContainsString('ristampando la scheda', $esito['nota']);
    }
}
