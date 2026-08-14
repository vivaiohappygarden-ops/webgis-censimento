<?php

namespace Tests\Feature;

use App\Models\PhytoTreatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PhytoTreatmentTest extends TestCase
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
        $this->area = $this->createArea($this->organization, ['name' => 'Parco delle Rose']);
    }

    private function validPayload(array $overrides = []): array
    {
        return [
            'area_id' => $this->area->id,
            'treated_on' => now('Europe/Rome')->toDateString(),
            'product_name' => 'Karate Zeon',
            'registration_number' => '12345',
            'active_substance' => 'lambda-cialotrina',
            'vegetation' => 'tigli in filare',
            'adversity' => 'afidi del tiglio',
            'method' => 'irrorazione',
            'quantity' => 1.5,
            'unit' => 'l',
            'water_volume_l' => 300,
            'surface_sqm' => 2500,
            'reentry_hours' => 48,
            ...$overrides,
        ];
    }

    public function test_crud_with_versioning_and_register_filters(): void
    {
        $created = $this->postJson('/api/v1/phyto-treatments', $this->validPayload())
            ->assertCreated()->json('data');
        $this->assertSame('Karate Zeon', $created['product_name']);
        $this->assertSame('Parco delle Rose', $created['area']['name']);
        $this->assertSame(1, $created['version']);

        // Modifica con versione corretta, poi con versione superata (409)
        $this->patchJson("/api/v1/phyto-treatments/{$created['id']}", [
            'version' => 1, 'adversity' => 'processionaria del pino',
        ])->assertOk()->assertJsonPath('data.adversity', 'processionaria del pino');
        $this->patchJson("/api/v1/phyto-treatments/{$created['id']}", [
            'version' => 1, 'adversity' => 'altro',
        ])->assertStatus(409);

        // Trattamento dell'anno scorso: il filtro per anno separa i registri
        PhytoTreatment::create([
            ...$this->validPayload(['treated_on' => now('Europe/Rome')->subYear()->toDateString()]),
            'tenant_id' => $this->organization->id,
        ]);
        $thisYear = $this->getJson('/api/v1/phyto-treatments?year='.now('Europe/Rome')->year)
            ->assertOk()->json('data');
        $this->assertCount(1, $thisYear);
        $lastYear = $this->getJson('/api/v1/phyto-treatments?year='.(now('Europe/Rome')->year - 1))
            ->assertOk()->json('data');
        $this->assertCount(1, $lastYear);

        // Eliminazione con soft delete: sparisce dall'elenco, resta agli atti
        $this->deleteJson("/api/v1/phyto-treatments/{$created['id']}")->assertNoContent();
        $this->assertCount(0, $this->getJson('/api/v1/phyto-treatments?year='.now('Europe/Rome')->year)->json('data'));
        $this->assertNotNull(PhytoTreatment::withTrashed()->find($created['id'])->deleted_at);
    }

    public function test_validation_rejects_bad_data(): void
    {
        // Data futura: un registro non si compila in anticipo
        $this->postJson('/api/v1/phyto-treatments', $this->validPayload([
            'treated_on' => now('Europe/Rome')->addDay()->toDateString(),
        ]))->assertUnprocessable();

        // Quantita' zero o con troppi decimali
        $this->postJson('/api/v1/phyto-treatments', $this->validPayload(['quantity' => 0]))
            ->assertUnprocessable();
        $this->postJson('/api/v1/phyto-treatments', $this->validPayload(['quantity' => 0.0004]))
            ->assertUnprocessable();

        // Unita' e metodo fuori elenco
        $this->postJson('/api/v1/phyto-treatments', $this->validPayload(['unit' => 'quintali']))
            ->assertUnprocessable();
        $this->postJson('/api/v1/phyto-treatments', $this->validPayload(['method' => 'aereo']))
            ->assertUnprocessable();

        // L'albero indicato deve stare nell'area del trattamento
        $otherArea = $this->createArea($this->organization, ['name' => 'Altra area']);
        $asset = \App\Models\Asset::create([
            'tenant_id' => $this->organization->id,
            'area_id' => $otherArea->id,
            'object_type_id' => $this->makeObjectType($this->organization, 'P')->id,
            'census_code' => 'AL-0001',
            'geom' => \App\Support\Geometry::toEwkb($this->pointGeometry()),
        ]);
        $this->postJson('/api/v1/phyto-treatments', $this->validPayload(['asset_id' => $asset->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('asset_id');

        // Nell'area giusta invece passa
        $this->postJson('/api/v1/phyto-treatments', $this->validPayload([
            'area_id' => $otherArea->id, 'asset_id' => $asset->id,
        ]))->assertCreated();
    }

    public function test_review_regressions_soft_deleted_asset_uuid_case_and_totals(): void
    {
        // La coltura trattata e' un campo minimo del registro
        $this->postJson('/api/v1/phyto-treatments', $this->validPayload(['vegetation' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('vegetation');

        $asset = \App\Models\Asset::create([
            'tenant_id' => $this->organization->id,
            'area_id' => $this->area->id,
            'object_type_id' => $this->makeObjectType($this->organization, 'P')->id,
            'census_code' => 'AL-0100',
            'geom' => \App\Support\Geometry::toEwkb($this->pointGeometry()),
        ]);

        // Un area_id maiuscolo equivale allo stesso uuid: niente falso 422
        $created = $this->postJson('/api/v1/phyto-treatments', $this->validPayload([
            'area_id' => strtoupper($this->area->id), 'asset_id' => $asset->id,
        ]))->assertCreated()->json('data');
        $this->assertSame($this->area->id, $created['area_id']);

        // L'albero poi abbattuto (soft delete) non blocca le correzioni
        // della riga storica e resta leggibile nel registro
        $asset->delete();
        $this->patchJson("/api/v1/phyto-treatments/{$created['id']}", [
            'version' => 1, 'notes' => 'correzione dopo abbattimento',
        ])->assertOk()->assertJsonPath('data.asset.census_code', 'AL-0100');
        $row = collect($this->getJson('/api/v1/phyto-treatments')->assertOk()->json('data'))
            ->firstWhere('id', $created['id']);
        $this->assertSame('AL-0100', $row['asset']['census_code']);

        // Rimandare lo stesso albero (come fa la pagina) resta valido...
        $this->patchJson("/api/v1/phyto-treatments/{$created['id']}", [
            'version' => 2, 'asset_id' => $asset->id, 'notes' => 'ancora lui',
        ])->assertOk();

        // ...ma passare a un ALTRO elemento gia' abbattuto e' rifiutato
        $other = \App\Models\Asset::create([
            'tenant_id' => $this->organization->id,
            'area_id' => $this->area->id,
            'object_type_id' => $this->makeObjectType($this->organization, 'P')->id,
            'census_code' => 'AL-0101',
            'geom' => \App\Support\Geometry::toEwkb($this->pointGeometry()),
        ]);
        $other->delete();
        $this->patchJson("/api/v1/phyto-treatments/{$created['id']}", [
            'version' => 3, 'asset_id' => $other->id,
        ])->assertNotFound();

        // L'elenco dichiara il totale, cosi' la pagina segnala i tagli
        $this->assertSame(1, $this->getJson('/api/v1/phyto-treatments')->assertOk()->json('total'));
    }

    public function test_register_pdf(): void
    {
        $this->postJson('/api/v1/phyto-treatments', $this->validPayload())->assertCreated();

        $pdf = $this->get('/api/v1/phyto-treatments/register-pdf?year='.now('Europe/Rome')->year)
            ->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertGreaterThan(1500, strlen($pdf->getContent()));

        // Filtro per area inesistente rifiutato come UUID non valido
        $this->get('/api/v1/phyto-treatments/register-pdf?area_id=abc')->assertStatus(422);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $created = $this->postJson('/api/v1/phyto-treatments', $this->validPayload())
            ->assertCreated()->json('data');

        // L'operatore consulta il registro ma non scrive
        $operator = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);
        $this->getJson('/api/v1/phyto-treatments')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/phyto-treatments', $this->validPayload())->assertForbidden();
        $this->deleteJson("/api/v1/phyto-treatments/{$created['id']}")->assertForbidden();

        // Il cliente non vede il registro
        $client = \App\Models\User::factory()->create([
            'tenant_id' => $this->organization->id, 'user_type' => 'client_portal',
        ]);
        $client->assignRole('cliente');
        $this->actingAsTenantUser($client);
        $this->getJson('/api/v1/phyto-treatments')->assertForbidden();

        // Un altro tenant non vede nulla e non tocca nulla
        [, $foreign] = $this->createTenantUser();
        $this->actingAsTenantUser($foreign);
        $this->assertSame([], $this->getJson('/api/v1/phyto-treatments')->assertOk()->json('data'));
        $this->patchJson("/api/v1/phyto-treatments/{$created['id']}", ['adversity' => 'x'])
            ->assertNotFound();
    }
}
