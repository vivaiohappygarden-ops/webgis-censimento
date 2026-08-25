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
        for ($i = 0; $i < $oltre; $i++) {
            $this->post("/api/v1/assets/{$assetId}/photos", [
                'photo' => UploadedFile::fake()->image("foto{$i}.jpg", 60, 40),
                'category' => 'census',
            ])->assertCreated();
        }

        $this->get("/api/v1/assets/{$assetId}/pdf")->assertOk();

        $dati = $stampe->dati['pdf.asset'];
        $this->assertCount(\App\Services\Photos\FotoStampa::MASSIMO, $dati['foto']);
        $this->assertStringContainsString("risultano {$oltre} fotografie", $dati['fotoNota']);
        $this->assertStringContainsString('le più recenti', $dati['fotoNota']);
    }
}
