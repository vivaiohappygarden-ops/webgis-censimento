<?php

namespace Tests\Feature;

use App\Models\IrrigationSystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class IrrigationTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->actingAsTenantUser($this->user);
        $this->area = $this->createArea($this->organization);
    }

    private function makeSystem(array $attributes = []): array
    {
        return $this->postJson('/api/v1/irrigation-systems', [
            'area_id' => $this->area->id,
            'name' => 'Impianto test',
            'system_type' => 'goccia',
            ...$attributes,
        ])->assertCreated()->json('data');
    }

    public function test_crud_lifecycle_with_sectors_count_in_list(): void
    {
        $system = $this->makeSystem([
            'water_source' => 'pozzo',
            'season_opens_on' => '2026-04-01',
            'season_closes_on' => '2026-10-15',
        ]);
        $this->assertSame('active', $system['status']);

        $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", ['sectors' => [
            ['name' => 'Siepi', 'flow_lpm' => 12.5, 'run_minutes' => 30, 'runs_per_week' => 3],
            ['name' => 'Aiuole', 'description' => 'Lato fontana'],
        ]])->assertOk()->assertJsonCount(2, 'data.sectors');

        $list = $this->getJson('/api/v1/irrigation-systems')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame(2, $list[0]['sectors_count']);
        $this->assertSame($this->area->name, $list[0]['area']['name']);

        $this->patchJson("/api/v1/irrigation-systems/{$system['id']}", [
            'status' => 'winterized', 'notes' => 'Scaricato a novembre.',
        ])->assertOk()->assertJsonPath('data.status', 'winterized');

        $this->deleteJson("/api/v1/irrigation-systems/{$system['id']}")->assertNoContent();
        $this->getJson("/api/v1/irrigation-systems/{$system['id']}")->assertNotFound();
        $this->assertNotNull(IrrigationSystem::withTrashed()->find($system['id'])->deleted_at);

        // I settori non hanno soft delete: con l'impianto eliminato sarebbero
        // orfani irraggiungibili, quindi vengono rimossi fisicamente
        $this->assertSame(0, \App\Models\IrrigationSector::withoutGlobalScopes()
            ->where('system_id', $system['id'])->count());
    }

    public function test_stale_version_gets_409_on_update_and_sectors(): void
    {
        $system = $this->makeSystem();
        $this->assertSame(1, $system['version']);

        // Primo salvataggio con la versione giusta: passa e incrementa
        $updated = $this->patchJson("/api/v1/irrigation-systems/{$system['id']}", [
            'version' => 1, 'name' => 'Rinominato',
        ])->assertOk()->json('data');
        $this->assertSame(2, $updated['version']);

        // Chi è rimasto alla versione 1 non sovrascrive: 409
        $this->patchJson("/api/v1/irrigation-systems/{$system['id']}", [
            'version' => 1, 'name' => 'Sovrascritto',
        ])->assertStatus(409);
        $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", [
            'version' => 1, 'sectors' => [['name' => 'Perso']],
        ])->assertStatus(409);

        // Anche la sostituzione dei settori incrementa la versione
        $after = $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", [
            'version' => 2, 'sectors' => [['name' => 'Salvato']],
        ])->assertOk()->json('data');
        $this->assertSame(3, $after['version']);

        // Senza versione (altri client API) il salvataggio resta permesso
        $this->patchJson("/api/v1/irrigation-systems/{$system['id']}", ['name' => 'Senza lock'])
            ->assertOk();
    }

    public function test_area_with_irrigation_system_cannot_be_deleted(): void
    {
        $this->makeSystem();

        $this->deleteJson("/api/v1/areas/{$this->area->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('area');

        // Le aree senza impianti (e senza elementi) restano eliminabili
        $emptyArea = $this->createArea($this->organization, ['name' => 'Area vuota']);
        $this->deleteJson("/api/v1/areas/{$emptyArea->id}")->assertNoContent();
    }

    public function test_validation_messages_are_in_italian(): void
    {
        $errors = $this->postJson('/api/v1/irrigation-systems', ['area_id' => $this->area->id])
            ->assertUnprocessable()->json('errors');
        $this->assertSame('Il campo nome è obbligatorio.', $errors['name'][0]);

        $system = $this->makeSystem();
        $errors = $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", ['sectors' => [
            ['name' => 'Prato', 'run_minutes' => 2000],
        ]])->assertUnprocessable()->json('errors');
        $this->assertStringContainsString('minuti per ciclo', $errors['sectors.0.run_minutes'][0]);
        $this->assertStringContainsString('maggiore di 1440', $errors['sectors.0.run_minutes'][0]);
    }

    public function test_validation_rejects_bad_season_type_and_foreign_area(): void
    {
        // Stagione al contrario in creazione
        $this->postJson('/api/v1/irrigation-systems', [
            'area_id' => $this->area->id, 'name' => 'X',
            'season_opens_on' => '2026-10-01', 'season_closes_on' => '2026-04-01',
        ])->assertUnprocessable();

        // Tipo fuori elenco
        $this->postJson('/api/v1/irrigation-systems', [
            'area_id' => $this->area->id, 'name' => 'X', 'system_type' => 'nebulizzazione',
        ])->assertUnprocessable();

        // Area di un altro tenant: come inesistente
        [$other] = $this->createTenantUser();
        $foreignArea = $this->createArea($other);
        $this->postJson('/api/v1/irrigation-systems', [
            'area_id' => $foreignArea->id, 'name' => 'X',
        ])->assertNotFound();
    }

    public function test_partial_update_cannot_invert_the_season(): void
    {
        $system = $this->makeSystem([
            'season_opens_on' => '2026-04-01',
            'season_closes_on' => '2026-10-15',
        ]);

        // La sola data di chiusura, spostata prima dell'apertura già salvata
        $this->patchJson("/api/v1/irrigation-systems/{$system['id']}", [
            'season_closes_on' => '2026-03-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('season_closes_on');

        // Spostando anche l'apertura la coppia torna valida
        $this->patchJson("/api/v1/irrigation-systems/{$system['id']}", [
            'season_opens_on' => '2026-02-01', 'season_closes_on' => '2026-03-01',
        ])->assertOk();
    }

    public function test_sectors_are_replaced_in_full_and_duplicate_names_rejected(): void
    {
        $system = $this->makeSystem();

        $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", ['sectors' => [
            ['name' => 'Vecchio settore'],
        ]])->assertOk();

        // Nomi uguali a meno di maiuscole: rifiutati
        $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", ['sectors' => [
            ['name' => 'Prato est'], ['name' => 'prato EST'],
        ]])->assertUnprocessable();

        // Minuti fuori scala
        $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", ['sectors' => [
            ['name' => 'Prato', 'run_minutes' => 2000],
        ]])->assertUnprocessable();

        $data = $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", ['sectors' => [
            ['name' => 'Nuovo A'], ['name' => 'Nuovo B'],
        ]])->assertOk()->json('data.sectors');

        // Sostituzione integrale: il vecchio settore non esiste più
        $this->assertSame(['Nuovo A', 'Nuovo B'], array_column($data, 'name'));
    }

    public function test_maintenance_work_order_starts_as_draft_on_the_area(): void
    {
        $system = $this->makeSystem();

        $workOrder = $this->postJson("/api/v1/irrigation-systems/{$system['id']}/work-order")
            ->assertCreated()->json('data');

        $this->assertSame('draft', $workOrder['status']);
        $this->assertSame('maintenance_plan', $workOrder['origin']);
        $this->assertSame($system['id'], $workOrder['origin_id']);
        $this->assertSame($this->area->id, $workOrder['area_id']);
        $this->assertStringContainsString('Impianto test', $workOrder['title']);
    }

    public function test_tenant_isolation_hides_foreign_systems(): void
    {
        $system = $this->makeSystem();

        [$other, $otherUser] = $this->createTenantUser();
        $this->actingAsTenantUser($otherUser);

        $this->assertSame([], $this->getJson('/api/v1/irrigation-systems')->assertOk()->json('data'));
        $this->getJson("/api/v1/irrigation-systems/{$system['id']}")->assertNotFound();
        $this->patchJson("/api/v1/irrigation-systems/{$system['id']}", ['name' => 'Rubato'])->assertNotFound();
    }

    public function test_operatore_reads_but_cannot_write_nor_create_orders(): void
    {
        $system = $this->makeSystem();

        [, $operator] = [null, \App\Models\User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);

        $this->getJson('/api/v1/irrigation-systems')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/irrigation-systems/{$system['id']}")->assertOk();

        $this->postJson('/api/v1/irrigation-systems', [
            'area_id' => $this->area->id, 'name' => 'Nuovo',
        ])->assertForbidden();
        $this->patchJson("/api/v1/irrigation-systems/{$system['id']}", ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", ['sectors' => []])->assertForbidden();
        $this->postJson("/api/v1/irrigation-systems/{$system['id']}/work-order")->assertForbidden();
        $this->deleteJson("/api/v1/irrigation-systems/{$system['id']}")->assertForbidden();
    }
}
