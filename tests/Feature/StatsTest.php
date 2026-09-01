<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\PhytoTreatment;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class StatsTest extends TestCase
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

    private function createTree(string $code, string $species): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => $code,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => explode(' ', $species)[0], 'species' => $species],
        ])->assertOk();

        return $id;
    }

    public function test_overview_aggregates_all_sections(): void
    {
        // Due tigli e un acero; un tiglio con valutazione VTA in classe C
        $tree = $this->createTree('ALB-0001', 'Tilia cordata');
        $this->createTree('ALB-0002', 'Tilia cordata');
        $this->createTree('ALB-0003', 'Acer campestre');
        $this->postJson("/api/v1/assets/{$tree}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now('Europe/Rome')->toDateString(),
            'failure_class' => 'C',
            'outcome' => 'monitor',
        ])->assertCreated();

        // Un ordine completato questo mese, uno in bozza. completed_at non
        // e' assegnabile in massa: senza forceFill resterebbe NULL in silenzio
        WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => WorkOrder::nextCode($this->organization->id),
            'title' => 'Potatura completata',
            'status' => 'completed',
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ])->forceFill(['completed_at' => now()])->save();
        WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => WorkOrder::nextCode($this->organization->id),
            'title' => 'Bozza',
            'status' => 'draft',
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        // Una segnalazione risolta in 2 giorni, una aperta (le date di
        // apertura e risoluzione si impostano con forceFill, vedi sopra)
        Issue::create([
            'tenant_id' => $this->organization->id,
            'code' => Issue::nextCode($this->organization->id),
            'status' => 'resolved',
            'severity' => 'high',
            'reporter_type' => 'internal',
            'channel' => 'backoffice',
            'description' => 'Risolta.',
        ])->forceFill(['created_at' => now()->subDays(2), 'resolved_at' => now()])->save();
        Issue::create([
            'tenant_id' => $this->organization->id,
            'code' => Issue::nextCode($this->organization->id),
            'status' => 'open',
            'severity' => 'low',
            'reporter_type' => 'internal',
            'channel' => 'backoffice',
            'description' => 'Aperta.',
        ]);

        // Due trattamenti quest'anno con lo stesso prodotto
        foreach (range(1, 2) as $i) {
            PhytoTreatment::create([
                'tenant_id' => $this->organization->id,
                'area_id' => $this->area->id,
                'treated_on' => now('Europe/Rome')->toDateString(),
                'product_name' => 'Karate Zeon',
                'vegetation' => 'tigli',
                'adversity' => 'afidi',
                'method' => 'irrorazione',
                'quantity' => 1,
                'unit' => 'l',
            ]);
        }

        $data = $this->getJson('/api/v1/stats/overview')->assertOk()->json('data');

        // Censimento
        $this->assertSame(3, $data['census']['assets_total']);
        $this->assertSame(3, $data['census']['trees_total']);
        $this->assertSame(1, $data['census']['areas_total']);
        $this->assertSame(2, $data['census']['by_species']['Tilia cordata']);
        $this->assertSame(1, $data['census']['by_species']['Acer campestre']);

        // VTA: un albero in classe C, anno corrente con 1 valutazione
        $this->assertSame(1, $data['vta']['by_class']['C']);
        $this->assertSame(1, $data['vta']['per_year'][(string) now('Europe/Rome')->year]);
        $this->assertCount(5, $data['vta']['per_year']);

        // Lavori: stati e serie mensile con 12 mesi completi
        $this->assertSame(1, $data['works']['by_status']['completed']);
        $this->assertSame(1, $data['works']['by_status']['draft']);
        $this->assertCount(12, $data['works']['completed_by_month']);
        $this->assertSame(1, array_sum($data['works']['completed_by_month']));

        // Segnalazioni: una aperta ora, risoluzione media 2 giorni
        $this->assertSame(1, $data['issues']['open_now']);
        $this->assertEqualsWithDelta(2.0, $data['issues']['avg_resolution_days'], 0.1);
        $this->assertSame(2, array_sum($data['issues']['opened_by_month']));
        $this->assertSame(1, array_sum($data['issues']['resolved_by_month']));

        // Fitosanitari
        $this->assertSame(2, $data['phyto']['per_year'][(string) now('Europe/Rome')->year]);
        $this->assertSame(2, $data['phyto']['by_product']['Karate Zeon']);
    }

    public function test_removed_and_deleted_trees_are_excluded_and_statuses_ordered(): void
    {
        $standing = $this->createTree('ALB-0001', 'Tilia cordata');
        $felled = $this->createTree('ALB-0002', 'Tilia cordata');
        $deleted = $this->createTree('ALB-0003', 'Acer campestre');

        // L'abbattuto era in classe D: non deve restare nel grafico dei rischi
        $this->postJson("/api/v1/assets/{$felled}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now('Europe/Rome')->toDateString(),
            'failure_class' => 'D',
            'outcome' => 'fell',
        ])->assertCreated();
        $this->postJson("/api/v1/assets/{$standing}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now('Europe/Rome')->toDateString(),
            'failure_class' => 'B',
            'outcome' => 'monitor',
        ])->assertCreated();

        $this->patchJson("/api/v1/assets/{$felled}", [
            'tree' => ['removed_on' => now('Europe/Rome')->toDateString(), 'removal_reason' => 'abbattimento'],
        ])->assertOk();
        $this->deleteJson("/api/v1/assets/{$deleted}")->assertNoContent();

        $data = $this->getJson('/api/v1/stats/overview')->assertOk()->json('data');

        // In piedi resta solo il primo; l'eliminato sparisce anche dalle specie
        $this->assertSame(1, $data['census']['trees_total']);
        $this->assertArrayNotHasKey('Acer campestre', $data['census']['by_species']);

        // Le classi contano solo gli alberi ancora in piedi
        $this->assertSame(['B' => 1], $data['vta']['by_class']);

        // Anche il cruscotto VTA esclude abbattuti ed eliminati
        $vta = $this->getJson('/api/v1/vta/dashboard')->assertOk()->json('data');
        $this->assertSame(1, $vta['trees_total']);
        $this->assertSame(1, $vta['assessed']);
        $this->assertSame(0, $vta['never_assessed']);

        // Gli stati dei lavori escono nell'ordine del flusso
        WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => WorkOrder::nextCode($this->organization->id),
            'title' => 'Completato',
            'status' => 'completed',
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => WorkOrder::nextCode($this->organization->id),
            'title' => 'Bozza',
            'status' => 'draft',
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $statuses = array_keys($this->getJson('/api/v1/stats/overview')->json('data.works.by_status'));
        $this->assertSame(['draft', 'completed'], $statuses);
    }

    public function test_un_dismesso_esce_da_tutte_le_consistenze(): void
    {
        // Il dismesso non ha removed_on: senza il filtro su assets.status
        // continuerebbe a contare come albero in piedi in ogni riquadro
        $this->createTree('ALB-0001', 'Tilia cordata');
        $dismesso = $this->createTree('ALB-0002', 'Acer campestre');
        $this->postJson("/api/v1/assets/{$dismesso}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now('Europe/Rome')->toDateString(),
            'failure_class' => 'D',
            'outcome' => 'fell',
        ])->assertCreated();
        $this->patchJson("/api/v1/assets/{$dismesso}", ['status' => 'dismissed'])->assertOk();

        $data = $this->getJson('/api/v1/stats/overview')->assertOk()->json('data');

        $this->assertSame(1, $data['census']['assets_total']);
        $this->assertSame(1, $data['census']['trees_total']);
        $this->assertArrayNotHasKey('Acer campestre', $data['census']['by_species']);
        // La classe D del dismesso non resta nel grafico dei rischi
        $this->assertSame([], $data['vta']['by_class']);

        // E lo scadenzario VTA non lo conta ne' lo elenca
        $vta = $this->getJson('/api/v1/vta/dashboard')->assertOk()->json('data');
        $this->assertSame(1, $vta['trees_total']);
        $this->assertSame(0, $vta['assessed']);
        $alberi = $this->getJson('/api/v1/vta/alberi')->assertOk()->json('data');
        $this->assertCount(1, $alberi);
        $this->assertSame('ALB-0001', $alberi[0]['census_code']);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $this->createTree('ALB-0001', 'Tilia cordata');

        // L'operatore vede le statistiche (ha works.view e assets.view)
        $operator = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);
        $this->getJson('/api/v1/stats/overview')->assertOk()
            ->assertJsonPath('data.census.assets_total', 1);

        // Il cliente no
        $client = \App\Models\User::factory()->create([
            'tenant_id' => $this->organization->id, 'user_type' => 'client_portal',
        ]);
        $client->assignRole('cliente');
        $this->actingAsTenantUser($client);
        $this->getJson('/api/v1/stats/overview')->assertForbidden();

        // Un altro tenant vede solo i propri numeri (zero)
        [, $foreign] = $this->createTenantUser();
        $this->actingAsTenantUser($foreign);
        $this->getJson('/api/v1/stats/overview')->assertOk()
            ->assertJsonPath('data.census.assets_total', 0)
            ->assertJsonPath('data.issues.open_now', 0);
    }
}
