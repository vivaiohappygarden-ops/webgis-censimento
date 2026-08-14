<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class EstimateTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $client;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->actingAsTenantUser($this->user);
        $this->client = Client::create([
            'tenant_id' => $this->organization->id, 'name' => 'Condominio Test', 'client_type' => 'private',
        ]);
    }

    private function makeEstimate(array $attributes = []): array
    {
        return $this->postJson('/api/v1/estimates', [
            'client_id' => $this->client->id,
            'title' => 'Potatura siepi 2026',
            ...$attributes,
        ])->assertCreated()->json('data');
    }

    public function test_lifecycle_with_items_totals_and_yearly_numbering(): void
    {
        $estimate = $this->makeEstimate();
        $this->assertSame('PRE-'.now()->year.'-0001', $estimate['code']);
        $this->assertSame('draft', $estimate['status']);

        $second = $this->makeEstimate(['title' => 'Secondo preventivo']);
        $this->assertSame('PRE-'.now()->year.'-0002', $second['code']);

        $body = $this->putJson("/api/v1/estimates/{$estimate['id']}/items", ['items' => [
            ['description' => 'Potatura siepe di lauro', 'unit' => 'ml', 'quantity' => 120, 'unit_price' => 4.5],
            ['description' => 'Smaltimento risulte', 'unit' => 'corpo', 'quantity' => 1, 'unit_price' => 80],
        ]])->assertOk()->json('data');

        // 120*4,50 + 80 = 620; IVA 22% = 136,40; totale 756,40
        $this->assertEquals(620.0, $body['subtotal']);
        $this->assertEquals(136.4, $body['vat']);
        $this->assertEquals(756.4, $body['total']);

        // L'elenco riporta imponibile e conteggio voci
        $rows = $this->getJson('/api/v1/estimates')->assertOk()->json('data');
        $mine = collect($rows)->firstWhere('id', $estimate['id']);
        $this->assertSame(2, $mine['items_count']);
        $this->assertEquals(620.0, (float) $mine['subtotal']);

        // PDF con totali in convenzione italiana
        $pdf = $this->get("/api/v1/estimates/{$estimate['id']}/pdf")->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertGreaterThan(2000, strlen($pdf->getContent()));
    }

    public function test_flow_guards_editing_and_deletion(): void
    {
        $estimate = $this->makeEstimate();

        // Senza voci non si invia
        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", ['status' => 'sent'])
            ->assertUnprocessable();

        $this->putJson("/api/v1/estimates/{$estimate['id']}/items", ['items' => [
            ['description' => 'Voce', 'unit' => 'cad', 'quantity' => 1, 'unit_price' => 100],
        ]])->assertOk();

        // draft -> accepted diretto non ammesso
        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", ['status' => 'accepted'])
            ->assertUnprocessable();

        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", ['status' => 'sent'])
            ->assertOk()->assertJsonPath('data.status', 'sent');

        // Un preventivo inviato non si modifica ne' si elimina
        $this->patchJson("/api/v1/estimates/{$estimate['id']}", ['title' => 'Cambiato'])
            ->assertUnprocessable();
        $this->putJson("/api/v1/estimates/{$estimate['id']}/items", ['items' => []])
            ->assertUnprocessable();
        $this->deleteJson("/api/v1/estimates/{$estimate['id']}")->assertUnprocessable();

        // Accettazione e ordine di lavoro (una volta sola)
        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", ['status' => 'accepted'])->assertOk();
        $workOrder = $this->postJson("/api/v1/estimates/{$estimate['id']}/work-order")
            ->assertCreated()->json('data');
        $this->assertSame('estimate', $workOrder['origin']);
        $this->assertSame($estimate['id'], $workOrder['origin_id']);
        $this->assertSame($this->client->id, $workOrder['client_id']);
        $this->postJson("/api/v1/estimates/{$estimate['id']}/work-order")->assertUnprocessable();

        // Un preventivo accettato e' definitivo
        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", ['status' => 'draft'])
            ->assertUnprocessable();
    }

    public function test_rejected_returns_to_draft_and_deletion_removes_items(): void
    {
        $estimate = $this->makeEstimate();
        $this->putJson("/api/v1/estimates/{$estimate['id']}/items", ['items' => [
            ['description' => 'Voce', 'unit' => 'cad', 'quantity' => 2, 'unit_price' => 10],
        ]])->assertOk();
        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", ['status' => 'sent'])->assertOk();
        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", ['status' => 'rejected'])->assertOk();

        // Dal rifiuto si torna in bozza per correggere e rilanciare
        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", ['status' => 'draft'])->assertOk();

        $this->deleteJson("/api/v1/estimates/{$estimate['id']}")->assertNoContent();
        $this->assertSame(0, EstimateItem::withoutGlobalScopes()
            ->where('estimate_id', $estimate['id'])->count());
        $this->assertNotNull(Estimate::withTrashed()->find($estimate['id'])->deleted_at);
    }

    public function test_version_conflict_and_validation(): void
    {
        $estimate = $this->makeEstimate();
        $this->assertSame(1, $estimate['version']);

        $this->patchJson("/api/v1/estimates/{$estimate['id']}", ['version' => 1, 'title' => 'Rinominato'])
            ->assertOk();
        $this->patchJson("/api/v1/estimates/{$estimate['id']}", ['version' => 1, 'title' => 'Sovrascritto'])
            ->assertStatus(409);

        // Quantita' zero e lavorazione inesistente rifiutate
        $this->putJson("/api/v1/estimates/{$estimate['id']}/items", ['items' => [
            ['description' => 'Voce', 'unit' => 'cad', 'quantity' => 0, 'unit_price' => 10],
        ]])->assertUnprocessable();
        $this->putJson("/api/v1/estimates/{$estimate['id']}/items", ['items' => [
            ['work_type_id' => \Illuminate\Support\Str::uuid7()->toString(), 'description' => 'Voce', 'unit' => 'cad', 'quantity' => 1, 'unit_price' => 10],
        ]])->assertUnprocessable();

        // Cliente di un altro tenant: come inesistente
        [$other] = $this->createTenantUser();
        $foreignClient = Client::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'name' => 'Altrui', 'client_type' => 'private',
        ]);
        $this->actingAsTenantUser($this->user);
        $this->postJson('/api/v1/estimates', [
            'client_id' => $foreignClient->id, 'title' => 'X',
        ])->assertNotFound();
    }

    public function test_decimal_precision_rounding_convention_and_transition_conflict(): void
    {
        $estimate = $this->makeEstimate();

        // Piu' di due decimali: rifiutato dalla validazione, non dal DB
        $this->putJson("/api/v1/estimates/{$estimate['id']}/items", ['items' => [
            ['description' => 'Voce', 'unit' => 'cad', 'quantity' => 0.004, 'unit_price' => 10],
        ]])->assertUnprocessable();
        $this->putJson("/api/v1/estimates/{$estimate['id']}/items", ['items' => [
            ['description' => 'Voce', 'unit' => 'cad', 'quantity' => 1, 'unit_price' => 1.005],
        ]])->assertUnprocessable();

        // Convenzione di fattura: ogni riga arrotondata a 2 decimali e IVA
        // sull'imponibile stampato. 3 righe da 0,33 x 1,01 (= 0,3333 -> 0,33
        // l'una): imponibile 0,99, non 1,00 come verrebbe sommando esatto
        $body = $this->putJson("/api/v1/estimates/{$estimate['id']}/items", ['items' => [
            ['description' => 'Riga A', 'unit' => 'cad', 'quantity' => 0.33, 'unit_price' => 1.01],
            ['description' => 'Riga B', 'unit' => 'cad', 'quantity' => 0.33, 'unit_price' => 1.01],
            ['description' => 'Riga C', 'unit' => 'cad', 'quantity' => 0.33, 'unit_price' => 1.01],
        ]])->assertOk()->json('data');
        $this->assertEquals(0.99, $body['subtotal']);
        $this->assertEquals(0.22, $body['vat']);
        $this->assertEquals(1.21, $body['total']);

        // Cambio di stato con versione superata: 409 e lo stato non cambia
        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", [
            'status' => 'sent', 'version' => 1,
        ])->assertStatus(409);
        $this->assertSame('draft', Estimate::query()->find($estimate['id'])->status);

        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", [
            'status' => 'sent', 'version' => $body['version'],
        ])->assertOk();
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $estimate = $this->makeEstimate();

        // L'operatore legge ma non scrive
        $operator = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);
        $this->getJson('/api/v1/estimates')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/estimates', ['client_id' => $this->client->id, 'title' => 'X'])
            ->assertForbidden();
        $this->postJson("/api/v1/estimates/{$estimate['id']}/transition", ['status' => 'sent'])
            ->assertForbidden();

        // Un altro tenant non vede nulla
        [, $foreign] = $this->createTenantUser();
        $this->actingAsTenantUser($foreign);
        $this->assertSame([], $this->getJson('/api/v1/estimates')->assertOk()->json('data'));
        $this->getJson("/api/v1/estimates/{$estimate['id']}")->assertNotFound();
    }
}
