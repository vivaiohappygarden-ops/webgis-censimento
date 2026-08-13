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

        $this->postJson("/api/v1/irrigation-systems/{$system['id']}/readings", [
            'read_on' => '2026-06-01', 'value_m3' => 42,
        ])->assertCreated();

        $this->deleteJson("/api/v1/irrigation-systems/{$system['id']}")->assertNoContent();
        $this->getJson("/api/v1/irrigation-systems/{$system['id']}")->assertNotFound();
        $this->assertNotNull(IrrigationSystem::withTrashed()->find($system['id'])->deleted_at);

        // Settori e letture non hanno soft delete: con l'impianto eliminato
        // sarebbero orfani irraggiungibili, quindi vengono rimossi fisicamente
        $this->assertSame(0, \App\Models\IrrigationSector::withoutGlobalScopes()
            ->where('system_id', $system['id'])->count());
        $this->assertSame(0, \App\Models\IrrigationMeterReading::withoutGlobalScopes()
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

    public function test_meter_readings_compute_consumption_and_weekly_summary(): void
    {
        $system = $this->makeSystem();
        $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", ['sectors' => [
            ['name' => 'Siepi', 'flow_lpm' => 12.5, 'run_minutes' => 30, 'runs_per_week' => 3],
            ['name' => 'Aiuole', 'flow_lpm' => 8, 'run_minutes' => 20, 'runs_per_week' => 4],
            ['name' => 'Alberi', 'flow_lpm' => 20, 'run_minutes' => 45, 'runs_per_week' => 2],
        ]])->assertOk();

        $post = fn (string $day, float $value) => $this->postJson(
            "/api/v1/irrigation-systems/{$system['id']}/readings",
            ['read_on' => $day, 'value_m3' => $value],
        );
        $post('2026-06-01', 100.00)->assertCreated();
        $post('2026-06-08', 103.50)->assertCreated();
        $body = $post('2026-06-15', 107.60)->assertCreated()->json();

        // Elenco dalla più recente, con consumo e giorni dal precedente
        $this->assertSame(['2026-06-15', '2026-06-08', '2026-06-01'], array_column($body['data'], 'read_on'));
        $this->assertSame(4.1, $body['data'][0]['consumption_m3']);
        $this->assertSame(7, $body['data'][0]['days_since_previous']);
        $this->assertSame(3.5, $body['data'][1]['consumption_m3']);
        $this->assertNull($body['data'][2]['consumption_m3']);

        // Stima dal programma: (12.5*30*3 + 8*20*4 + 20*45*2) / 1000 = 3.57 m3
        $this->assertSame(3.57, $body['summary']['weekly_estimate_m3']);
        // Consumo reale dalle ultime due letture: 4.1 m3 in 7 giorni
        $this->assertSame(4.1, $body['summary']['actual_weekly_m3']);

        // Contatore azzerato/sostituito: consumo non calcolabile, niente stima reale
        $body = $post('2026-06-22', 5.00)->assertCreated()->json();
        $this->assertNull($body['data'][0]['consumption_m3']);
        $this->assertNull($body['summary']['actual_weekly_m3']);

        // Eliminando l'ultima lettura la stima reale torna disponibile
        $deleted = $this->deleteJson("/api/v1/irrigation-systems/{$system['id']}/readings/{$body['data'][0]['id']}")
            ->assertOk()->json();
        $this->assertCount(3, $deleted['data']);
        $this->assertSame(4.1, $deleted['summary']['actual_weekly_m3']);
    }

    public function test_meter_reading_validation_and_isolation(): void
    {
        $system = $this->makeSystem();
        $url = "/api/v1/irrigation-systems/{$system['id']}/readings";

        $this->postJson($url, ['read_on' => '2026-06-01', 'value_m3' => -5])->assertUnprocessable();
        // "Domani" va calcolato nel fuso italiano: intorno alla mezzanotte
        // il domani UTC è già l'oggi di Roma (e sarebbe accettato)
        $this->postJson($url, ['read_on' => now('Europe/Rome')->addDay()->toDateString(), 'value_m3' => 10])->assertUnprocessable();

        $this->postJson($url, ['read_on' => '2026-06-01', 'value_m3' => 10])->assertCreated();
        // Una sola lettura per giorno per impianto
        $this->postJson($url, ['read_on' => '2026-06-01', 'value_m3' => 11])
            ->assertUnprocessable()->assertJsonValidationErrors('read_on');

        // Un altro tenant non vede né tocca le letture
        [, $otherUser] = $this->createTenantUser();
        $this->actingAsTenantUser($otherUser);
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, ['read_on' => '2026-06-02', 'value_m3' => 12])->assertNotFound();
    }

    public function test_reading_cap_keeps_the_most_recent_entries(): void
    {
        $system = $this->makeSystem();

        // 501 letture giornaliere: il tetto di 500 deve scartare la più
        // vecchia, non la più recente, e il riepilogo usa le vere ultime due
        $start = \Illuminate\Support\Carbon::parse('2025-01-01');
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = [
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'tenant_id' => $this->organization->id,
                'system_id' => $system['id'],
                'read_on' => $start->copy()->addDays($i)->toDateString(),
                'value_m3' => 100 + $i,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            \App\Models\IrrigationMeterReading::query()->insert($chunk);
        }

        $body = $this->getJson("/api/v1/irrigation-systems/{$system['id']}/readings")
            ->assertOk()->json();

        $this->assertCount(500, $body['data']);
        $this->assertSame($start->copy()->addDays(500)->toDateString(), $body['data'][0]['read_on']);
        $this->assertSame($start->copy()->addDays(1)->toDateString(), end($body['data'])['read_on']);
        $this->assertEquals(7, $body['summary']['actual_weekly_m3']);
    }

    public function test_reading_rejects_extra_decimals_and_uses_rome_today(): void
    {
        $system = $this->makeSystem();
        $url = "/api/v1/irrigation-systems/{$system['id']}/readings";

        // La colonna ha due decimali: un terzo decimale verrebbe arrotondato
        // in silenzio dal database, quindi va rifiutato
        $this->postJson($url, ['read_on' => '2026-06-01', 'value_m3' => 100.005])
            ->assertUnprocessable()->assertJsonValidationErrors('value_m3');

        // 22:30 UTC = 00:30 del giorno dopo in Italia: la lettura con la data
        // italiana corrente deve essere accettata, il dopodomani no
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-13 22:30:00', 'UTC'));
        $this->postJson($url, ['read_on' => '2026-08-14', 'value_m3' => 10])->assertCreated();
        $this->postJson($url, ['read_on' => '2026-08-15', 'value_m3' => 11])->assertUnprocessable();
        $this->travelBack();

        // Messaggi con i nomi dei campi tradotti
        $errors = $this->postJson($url, ['read_on' => '2026-06-02', 'value_m3' => -5])
            ->assertUnprocessable()->json('errors');
        $this->assertStringContainsString('valore del contatore', $errors['value_m3'][0]);
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
        $this->getJson("/api/v1/irrigation-systems/{$system['id']}/readings")->assertOk();

        $this->postJson('/api/v1/irrigation-systems', [
            'area_id' => $this->area->id, 'name' => 'Nuovo',
        ])->assertForbidden();
        $this->postJson("/api/v1/irrigation-systems/{$system['id']}/readings", [
            'read_on' => '2026-06-01', 'value_m3' => 10,
        ])->assertForbidden();
        $this->patchJson("/api/v1/irrigation-systems/{$system['id']}", ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/irrigation-systems/{$system['id']}/sectors", ['sectors' => []])->assertForbidden();
        $this->postJson("/api/v1/irrigation-systems/{$system['id']}/work-order")->assertForbidden();
        $this->deleteJson("/api/v1/irrigation-systems/{$system['id']}")->assertForbidden();
    }
}
