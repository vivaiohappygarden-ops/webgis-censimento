<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class InstrumentalAnalysisTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private string $assessmentId;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $area = $this->createArea($this->organization);
        $type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);

        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'ALB-STR',
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $this->assessmentId = $this->postJson("/api/v1/assets/{$assetId}/assessments", [
            'assessment_type' => 'vta_instrumental',
            'assessed_on' => now()->toDateString(),
            'failure_class' => 'C',
        ])->json('data.id');
    }

    public function test_analysis_can_be_registered_and_listed(): void
    {
        $this->postJson("/api/v1/assessments/{$this->assessmentId}/instrumental-analyses", [
            'instrument_type' => 'resistograph',
            'instrument_model' => 'IML RESI PD-400',
            'measured_at' => now()->toDateString(),
            'measurement_height_cm' => 130,
            'notes' => 'Perdita di resistenza tra 8 e 14 cm.',
        ])->assertCreated()
            ->assertJsonPath('data.instrument_type', 'resistograph')
            ->assertJsonPath('data.measurement_height_cm', 130);

        $list = $this->getJson("/api/v1/assessments/{$this->assessmentId}/instrumental-analyses")
            ->assertOk()->json('data');
        $this->assertCount(1, $list);
    }

    public function test_analysis_with_pdf_report_creates_downloadable_document(): void
    {
        Storage::fake('local');

        $response = $this->post("/api/v1/assessments/{$this->assessmentId}/instrumental-analyses", [
            'instrument_type' => 'sonic_tomograph',
            'report' => UploadedFile::fake()->create('referto-tomografia.pdf', 250, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $documentId = $response->json('data.document_id');
        $this->assertNotNull($documentId);
        $this->assertSame('referto-tomografia.pdf', $response->json('data.document.title'));

        $document = Document::query()->withoutGlobalScopes()->findOrFail($documentId);
        $this->assertSame('instrumental_analysis', $document->subject_type);
        Storage::disk('local')->assertExists($document->s3_key);

        $this->get("/api/v1/documents/{$documentId}/file")->assertOk();
    }

    public function test_analysis_rejects_invalid_instrument_and_non_pdf_report(): void
    {
        $this->postJson("/api/v1/assessments/{$this->assessmentId}/instrumental-analyses", [
            'instrument_type' => 'laser_fantascientifico',
        ])->assertUnprocessable();

        Storage::fake('local');
        $this->post("/api/v1/assessments/{$this->assessmentId}/instrumental-analyses", [
            'instrument_type' => 'resistograph',
            'report' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_analysis_can_be_deleted_with_its_report(): void
    {
        Storage::fake('local');

        $created = $this->post("/api/v1/assessments/{$this->assessmentId}/instrumental-analyses", [
            'instrument_type' => 'pull_test',
            'report' => UploadedFile::fake()->create('referto.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->json('data');

        $document = Document::query()->withoutGlobalScopes()->findOrFail($created['document_id']);

        $this->deleteJson("/api/v1/instrumental-analyses/{$created['id']}")->assertNoContent();
        $this->assertCount(0, $this->getJson("/api/v1/assessments/{$this->assessmentId}/instrumental-analyses")->json('data'));

        // Il referto non resta orfano: file rimosso e documento non più scaricabile
        Storage::disk('local')->assertMissing($document->s3_key);
        $this->getJson("/api/v1/documents/{$document->id}/file")->assertNotFound();
    }

    public function test_analyses_and_reports_are_tenant_isolated(): void
    {
        Storage::fake('local');

        $documentId = $this->post("/api/v1/assessments/{$this->assessmentId}/instrumental-analyses", [
            'instrument_type' => 'resistograph',
            'report' => UploadedFile::fake()->create('riservato.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->json('data.document_id');

        [, $otherUser] = $this->createTenantUser();
        $this->actingAsTenantUser($otherUser);

        $this->getJson("/api/v1/assessments/{$this->assessmentId}/instrumental-analyses")
            ->assertNotFound();
        $this->getJson("/api/v1/documents/{$documentId}/file")
            ->assertNotFound();
    }
}
