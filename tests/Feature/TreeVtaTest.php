<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class TreeVtaTest extends TestCase
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
        $this->area = $this->createArea($this->organization);
        $this->treeType = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function createTreeAsset(): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => 'ALB-'.fake()->unique()->numberBetween(1, 9999),
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    public function test_tree_record_is_created_automatically(): void
    {
        $id = $this->createTreeAsset();

        $this->getJson("/api/v1/assets/{$id}")
            ->assertOk()
            ->assertJsonPath('data.tree.asset_id', $id);

        $this->assertDatabaseHas('trees', ['asset_id' => $id]);
    }

    public function test_tree_dendrometrics_can_be_updated(): void
    {
        $id = $this->createTreeAsset();

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => [
                'genus' => 'Tilia',
                'species' => 'Tilia cordata',
                'height_m' => 14.5,
                'dbh_cm' => 38,
                'is_monumental' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('data.tree.genus', 'Tilia')
            ->assertJsonPath('data.tree.is_monumental', true);

        $this->assertDatabaseHas('trees', ['asset_id' => $id, 'species' => 'Tilia cordata']);
    }

    public function test_vta_gets_automatic_recheck_date_from_failure_class(): void
    {
        $id = $this->createTreeAsset();

        $response = $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => '2026-06-01',
            'failure_class' => 'C',
            'outcome' => 'monitor',
        ]);

        // Classe C → ricontrollo a 24 mesi
        $response->assertCreated()->assertJsonPath('data.next_check_due', '2028-06-01T00:00:00.000000Z');

        $this->getJson("/api/v1/assets/{$id}/assessments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.failure_class', 'C');
    }

    public function test_vta_dashboard_reports_overdue_rechecks(): void
    {
        $id = $this->createTreeAsset();

        // VTA vecchia con classe C/D → ricontrollo a 12 mesi, ormai scaduto
        $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now()->subMonths(20)->toDateString(),
            'failure_class' => 'C/D',
            'outcome' => 'prescriptions',
            'prescriptions' => 'Potatura di alleggerimento e ricontrollo',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/vta/dashboard')->assertOk();

        $this->assertSame(1, $response->json('data.trees_total'));
        $this->assertSame(1, $response->json('data.assessed'));
        $this->assertSame(1, $response->json('data.overdue_count'));
        $this->assertSame('C/D', $response->json('data.overdue.0.failure_class'));
    }

    public function test_assessment_can_be_corrected_without_losing_its_analyses(): void
    {
        $id = $this->createTreeAsset();

        $assessment = $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => '2026-06-01',
            'failure_class' => 'C',
            'targets' => ['area giochi'],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/assessments/{$assessment['id']}/instrumental-analyses", [
            'instrument_type' => 'resistograph',
            'measures' => ['residuo sano' => '70%'],
        ])->assertCreated();

        // Refuso corretto: la classe cambia, le analisi restano attaccate
        $corretta = $this->patchJson("/api/v1/assessments/{$assessment['id']}", [
            'failure_class' => 'C/D',
            'targets' => ['area giochi', 'marciapiede'],
            'survey' => ['conclusioni' => 'Classe corretta dopo la tomografia.'],
            'version' => $assessment['version'],
        ])->assertOk()->json('data');

        $this->assertSame('C/D', $corretta['failure_class']);
        $this->assertSame(['area giochi', 'marciapiede'], $corretta['targets']);
        $this->getJson("/api/v1/assessments/{$assessment['id']}/instrumental-analyses")
            ->assertOk()->assertJsonCount(1, 'data');

        // Versione vecchia: modifica rifiutata
        $this->patchJson("/api/v1/assessments/{$assessment['id']}", [
            'failure_class' => 'A',
            'version' => $assessment['version'],
        ])->assertStatus(409);
    }

    public function test_correcting_an_issued_perizia_clears_its_protocol(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'tile.openstreetmap.org/*' => \Illuminate\Support\Facades\Http::response('', 500),
        ]);
        $id = $this->createTreeAsset();
        $assessment = $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => '2026-06-01',
            'failure_class' => 'C',
        ])->assertCreated()->json('data');

        $this->get("/api/v1/assessments/{$assessment['id']}/perizia-pdf")->assertOk();
        $emessa = \App\Models\TreeAssessment::query()->findOrFail($assessment['id']);
        $this->assertNotNull($emessa->report_number);

        $this->patchJson("/api/v1/assessments/{$assessment['id']}", ['failure_class' => 'D'])->assertOk();

        // Il numero già consegnato non finisce su un contenuto diverso
        $corretta = \App\Models\TreeAssessment::query()->findOrFail($assessment['id']);
        $this->assertNull($corretta->report_number);
        $this->assertNull($corretta->report_issued_at);

        $this->get("/api/v1/assessments/{$assessment['id']}/perizia-pdf")->assertOk();
        $nuova = \App\Models\TreeAssessment::query()->findOrFail($assessment['id']);
        $this->assertNotSame($emessa->report_number, $nuova->report_number);
    }

    public function test_assessments_are_rejected_for_non_tree_assets(): void
    {
        $benchType = $this->makeObjectType($this->organization, 'P', 'P219012');
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $benchType->id,
            'geometry' => $this->pointGeometry(9.1912, 45.4649),
        ])->json('data.id');

        $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now()->toDateString(),
        ])->assertUnprocessable();
    }
}
