<?php

namespace Tests\Feature;

use App\Models\Inspection;
use App\Models\NonConformity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class InspectionTest extends TestCase
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

    private function makeTemplate(string $target = 'area', ?array $items = null): array
    {
        $id = $this->postJson('/api/v1/inspection-templates', [
            'name' => 'Controllo area giochi',
            'code' => 'EN1176-'.fake()->unique()->numberBetween(100, 999),
            'target' => $target,
            'standard_ref' => 'UNI EN 1176-7:2020',
        ])->assertCreated()->json('data.id');

        $detail = $this->putJson("/api/v1/inspection-templates/{$id}/items", [
            'items' => $items ?? [
                ['question' => 'Superfici antitrauma integre'],
                ['question' => 'Assenza di parti taglienti'],
                ['question' => 'Cartellonistica presente', 'answer_type' => 'ok_ko_na', 'ko_creates_nc' => false],
            ],
        ])->assertOk()->json('data');

        return [$id, collect($detail['items'])];
    }

    public function test_template_with_items_and_version_freeze(): void
    {
        [$templateId, $items] = $this->makeTemplate();
        $this->assertCount(3, $items);

        // Senza ispezioni la modifica delle domande NON cambia versione
        $this->putJson("/api/v1/inspection-templates/{$templateId}/items", [
            'items' => [['question' => 'Unica domanda']],
        ])->assertOk()->assertJsonPath('data.version', 1);

        // Con un'ispezione registrata, la modifica avanza la versione
        $area = $this->createArea($this->organization);
        $itemId = $this->getJson("/api/v1/inspection-templates/{$templateId}")->json('data.items.0.id');
        $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId,
            'area_id' => $area->id,
            'answers' => [$itemId => ['value' => 'ok']],
        ])->assertOk();

        $this->putJson("/api/v1/inspection-templates/{$templateId}/items", [
            'items' => [['question' => 'Domanda rivista']],
        ])->assertOk()->assertJsonPath('data.version', 2);

        // L'ispezione resta congelata sulla versione 1 con il testo originale
        $inspection = Inspection::query()->sole();
        $this->assertSame(1, $inspection->template_version);
        $this->assertSame('Unica domanda', collect($inspection->answers)->first()['question']);

        // E il modello usato non si elimina
        $this->deleteJson("/api/v1/inspection-templates/{$templateId}")->assertUnprocessable();
    }

    public function test_outcome_is_computed_and_ko_items_open_nonconformities(): void
    {
        [$templateId, $items] = $this->makeTemplate();
        $area = $this->createArea($this->organization);

        $inspection = $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId,
            'area_id' => $area->id,
            'answers' => [
                $items[0]['id'] => ['value' => 'ko', 'note' => 'Gomma antitrauma sollevata vicino allo scivolo.'],
                $items[1]['id'] => ['value' => 'ok'],
                $items[2]['id'] => ['value' => 'ko', 'note' => 'Cartello sbiadito.'],
            ],
        ])->assertOk()->json('data');

        $this->assertSame('failed', $inspection['outcome']);

        // Solo la voce con ko_creates_nc=true apre la non conformità
        $nc = NonConformity::query()->sole();
        $this->assertSame('inspection', $nc->origin);
        $this->assertSame($inspection['id'], $nc->origin_id);
        $this->assertStringContainsString('Superfici antitrauma', $nc->description);
        $this->assertStringContainsString('Gomma antitrauma', $nc->description);
    }

    public function test_outcomes_passed_and_with_remarks(): void
    {
        [$templateId, $items] = $this->makeTemplate();
        $area = $this->createArea($this->organization);

        $all = fn (string $v1, string $v3) => [
            $items[0]['id'] => ['value' => $v1],
            $items[1]['id'] => ['value' => 'ok'],
            $items[2]['id'] => ['value' => $v3],
        ];

        $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId, 'area_id' => $area->id, 'answers' => $all('ok', 'ok'),
        ])->assertOk()->assertJsonPath('data.outcome', 'passed');

        // Un "non applicabile" declassa a superata con riserve
        $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId, 'area_id' => $area->id, 'answers' => $all('ok', 'na'),
        ])->assertOk()->assertJsonPath('data.outcome', 'passed_with_remarks');
    }

    public function test_number_answers_and_notes_on_free_types(): void
    {
        [$templateId, $items] = $this->makeTemplate(items: [
            ['question' => 'Altezza caduta libera (m)', 'answer_type' => 'number', 'ko_creates_nc' => false],
            ['question' => 'Osservazioni generali', 'answer_type' => 'text', 'ko_creates_nc' => false],
        ]);
        $detail = $this->getJson("/api/v1/inspection-templates/{$templateId}")->json('data.items');
        $area = $this->createArea($this->organization);

        // Un valore numerico JSON (non stringa) è accettato
        $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId, 'area_id' => $area->id,
            'answers' => [
                $detail[0]['id'] => ['value' => 1.8],
                $detail[1]['id'] => ['value' => 'Tutto in ordine.'],
            ],
        ])->assertOk()->assertJsonPath('data.outcome', 'passed');

        // Una nota su una risposta di tipo testo declassa a "con riserve"
        $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId, 'area_id' => $area->id,
            'answers' => [
                $detail[0]['id'] => ['value' => 2],
                $detail[1]['id'] => ['value' => 'In ordine.', 'note' => 'Da ricontrollare dopo le piogge.'],
            ],
        ])->assertOk()->assertJsonPath('data.outcome', 'passed_with_remarks');

        // Risposte a domande estranee: errore esplicito
        $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId, 'area_id' => $area->id,
            'answers' => [
                $detail[0]['id'] => ['value' => 2],
                $detail[1]['id'] => ['value' => 'Ok.'],
                (string) \Illuminate\Support\Str::uuid() => ['value' => 'estranea'],
            ],
        ])->assertUnprocessable();
    }

    public function test_target_and_answers_are_validated(): void
    {
        [$templateId, $items] = $this->makeTemplate(); // target: area
        $area = $this->createArea($this->organization);
        $type = $this->makeObjectType($this->organization, 'P');
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id, 'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        // Elemento su un modello per aree: rifiutato
        $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId, 'asset_id' => $assetId,
            'answers' => [$items[0]['id'] => ['value' => 'ok']],
        ])->assertUnprocessable();

        // Risposta mancante o valore non ammesso: rifiutati
        $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId, 'area_id' => $area->id,
            'answers' => [$items[0]['id'] => ['value' => 'ok']],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/inspections', [
            'template_id' => $templateId, 'area_id' => $area->id,
            'answers' => [
                $items[0]['id'] => ['value' => 'na'], // ok_ko non ammette na
                $items[1]['id'] => ['value' => 'ok'],
                $items[2]['id'] => ['value' => 'ok'],
            ],
        ])->assertUnprocessable();
    }

    public function test_operator_reads_but_cannot_manage_or_execute(): void
    {
        [$templateId, $items] = $this->makeTemplate();

        [, $operator] = [null, \App\Models\User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);

        $this->getJson('/api/v1/inspection-templates')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inspections')->assertOk();
        $this->postJson('/api/v1/inspection-templates', ['name' => 'Vietato'])->assertForbidden();
        $this->postJson('/api/v1/inspections', ['template_id' => $templateId, 'answers' => []])->assertForbidden();
    }

    public function test_inspection_completed_from_field_via_sync_command(): void
    {
        [$templateId, $items] = $this->makeTemplate();
        $area = $this->createArea($this->organization);

        [, $operator] = [null, \App\Models\User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);

        // Sul working set del device i modelli arrivano dal bootstrap
        $bootstrap = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json();
        $this->assertCount(1, $bootstrap['inspection_templates']);
        $this->assertCount(3, $bootstrap['inspection_templates'][0]['items']);

        $inspectionId = (string) \Illuminate\Support\Str::uuid();
        $fieldTime = now()->subHours(5)->startOfSecond();
        $command = [
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'device_seq' => 1,
            'type' => 'inspection.complete',
            'entity_id' => $inspectionId,
            'payload' => [
                'template_id' => $templateId,
                'area_id' => $area->id,
                'answers' => [
                    $items[0]['id'] => ['value' => 'ko', 'note' => 'Gomma sollevata.'],
                    $items[1]['id'] => ['value' => 'ok'],
                    $items[2]['id'] => ['value' => 'na'],
                ],
            ],
            'client_ts' => $fieldTime->toIso8601String(),
        ];
        $batch = [
            'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'device_id' => 'dev-test-0001',
            'schema' => 1,
            'commands' => [$command],
        ];

        $this->postJson('/api/v1/sync/batch', $batch)->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.outcome', 'failed');

        $inspection = Inspection::query()->findOrFail($inspectionId);
        $this->assertSame('failed', $inspection->outcome);
        $this->assertSame($operator->id, $inspection->inspector_id);
        $this->assertTrue($inspection->completed_at->equalTo($fieldTime));
        $this->assertSame('Superfici antitrauma integre', collect($inspection->answers)->first()['question']);

        // La voce KO ha aperto la non conformità con origine ispezione
        $nc = NonConformity::query()->sole();
        $this->assertSame('inspection', $nc->origin);
        $this->assertSame($inspectionId, $nc->origin_id);

        // Replay: nessun doppione; id riusato con chiave nuova: collisione
        $this->postJson('/api/v1/sync/batch', $batch)->assertOk()
            ->assertJsonPath('results.0.status', 'duplicate');
        $this->assertSame(1, Inspection::query()->count());
        $this->postJson('/api/v1/sync/batch', [
            ...$batch,
            'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'commands' => [[...$command, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()]],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'ID_COLLISION');
    }

    public function test_inspections_are_tenant_isolated(): void
    {
        [$templateId] = $this->makeTemplate();

        [, $otherUser] = $this->createTenantUser();
        $this->actingAsTenantUser($otherUser);

        $this->getJson('/api/v1/inspection-templates')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/inspection-templates/{$templateId}")->assertNotFound();
    }
}
