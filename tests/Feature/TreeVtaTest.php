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
        $this->assertSame(['C/D' => 1], $response->json('data.by_class'));
    }

    public function test_vta_tree_list_orders_by_urgency_and_reports_status(): void
    {
        // Tre alberi: mai valutato, scaduto, valutato di recente
        $maiValutato = $this->createTreeAsset();
        $scaduto = $this->createTreeAsset();
        $recente = $this->createTreeAsset();

        // Sullo scaduto una VTA vecchia in classe A e una piu' recente in C/D
        // gia' oltre il ricontrollo: nell'elenco deve valere l'ultima
        $this->postJson("/api/v1/assets/{$scaduto}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now()->subMonths(40)->toDateString(),
            'failure_class' => 'A',
        ])->assertCreated();
        $this->postJson("/api/v1/assets/{$scaduto}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now()->subMonths(20)->toDateString(),
            'failure_class' => 'C/D',
            'outcome' => 'prescriptions',
        ])->assertCreated();

        $this->postJson("/api/v1/assets/{$recente}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now()->subDays(10)->toDateString(),
            'failure_class' => 'A',
            'outcome' => 'ok',
        ])->assertCreated();

        $righe = $this->getJson('/api/v1/vta/alberi')->assertOk()->json();

        $this->assertSame(3, $righe['total']);
        // Prima le urgenze, poi i mai valutati, in coda i valutati a posto
        $this->assertSame([$scaduto, $maiValutato, $recente], array_column($righe['data'], 'id'));
        $this->assertSame(['scaduto', 'mai_valutato', 'valutato'], array_column($righe['data'], 'stato'));
        $this->assertSame('C/D', $righe['data'][0]['failure_class']);

        // I filtri di stato e classe restringono l'elenco
        $this->assertSame([$maiValutato], array_column(
            $this->getJson('/api/v1/vta/alberi?status=never')->json('data'), 'id'));
        $this->assertSame([$scaduto], array_column(
            $this->getJson('/api/v1/vta/alberi?status=overdue')->json('data'), 'id'));
        $this->assertSame([$recente], array_column(
            $this->getJson('/api/v1/vta/alberi?class=A')->json('data'), 'id'));

        // Una scadenza entro 30 giorni finisce fra gli "in scadenza"
        $this->postJson("/api/v1/assets/{$maiValutato}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now()->toDateString(),
            'failure_class' => 'C',
            'next_check_due' => now()->addDays(10)->toDateString(),
        ])->assertCreated();
        $inScadenza = $this->getJson('/api/v1/vta/alberi?status=upcoming')->json('data');
        $this->assertSame([$maiValutato], array_column($inScadenza, 'id'));
        $this->assertSame('in_scadenza', $inScadenza[0]['stato']);

        $this->getJson('/api/v1/vta/alberi?status=inesistente')->assertUnprocessable();
    }

    public function test_vta_tree_list_searches_and_filters_by_client(): void
    {
        // Due committenti distinti: ogni area creata dal test ha il suo
        $altraArea = $this->createArea($this->organization, ['name' => 'Area Altrove']);

        $qui = $this->createTreeAsset();
        $la = $this->postJson('/api/v1/assets', [
            'area_id' => $altraArea->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => 'ALT-0001',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$la}", [
            'tree' => ['genus' => 'Quercus', 'species' => 'Quercus robur'],
        ])->assertOk();

        $clienteLa = $altraArea->locality->site->client_id;

        // Il filtro per committente vale per elenco, cruscotto e tutelati
        $righe = $this->getJson("/api/v1/vta/alberi?client_id={$clienteLa}")->assertOk()->json();
        $this->assertSame([$la], array_column($righe['data'], 'id'));
        $this->assertSame('Area Altrove', $righe['data'][0]['area_name']);
        $this->assertSame('Cliente Test', $righe['data'][0]['client_name']);

        $cruscotto = $this->getJson("/api/v1/vta/dashboard?client_id={$clienteLa}")->assertOk()->json('data');
        $this->assertSame(1, $cruscotto['trees_total']);
        $this->assertSame(1, $cruscotto['never_assessed']);

        $this->patchJson("/api/v1/assets/{$qui}", [
            'tree' => ['is_monumental' => true, 'monumental_ref' => 'DM 757/2023'],
        ])->assertOk();
        $this->assertCount(0, $this->getJson("/api/v1/vta/tutelati?client_id={$clienteLa}")->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/vta/tutelati')->json('data'));

        // La ricerca testuale passa dallo stesso scope del censimento
        $trovati = $this->getJson('/api/v1/vta/alberi?q=quercus')->assertOk()->json('data');
        $this->assertSame([$la], array_column($trovati, 'id'));

        // Byte non leggibili in UTF-8: messaggio chiaro, non errore del server
        $this->getJson('/api/v1/vta/alberi?q='.rawurlencode("\xC3\x28rossi"))
            ->assertStatus(422)
            ->assertSee('non si possono leggere', false);
    }

    public function test_bulk_validation_counts_first_then_validates(): void
    {
        $pronto = $this->createTreeAsset();
        $senzaClasse = $this->createTreeAsset();
        $giaValidato = $this->createTreeAsset();

        $this->postJson("/api/v1/assets/{$pronto}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => '2026-06-01', 'failure_class' => 'B',
        ])->assertCreated();
        $this->postJson("/api/v1/assets/{$senzaClasse}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => '2026-06-02',
        ])->assertCreated();
        $valido = $this->postJson("/api/v1/assets/{$giaValidato}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => '2026-06-03', 'failure_class' => 'A',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/assessments/{$valido}/valida")->assertOk();

        // La prova conta senza scrivere: una da validare, una esclusa
        // (la già validata non è una bozza, quindi non compare)
        $prova = $this->postJson('/api/v1/vta/valida', [
            'asset_ids' => [$pronto, $senzaClasse, $giaValidato], 'prova' => 1,
        ])->assertOk()->json('data');
        $this->assertTrue($prova['prova']);
        $this->assertCount(1, $prova['validate']);
        $this->assertCount(1, $prova['saltate']);
        $this->assertStringContainsString('classe di propensione', $prova['saltate'][0]['motivo']);
        $this->assertSame(0, \App\Models\TreeAssessment::query()
            ->where('tree_id', $pronto)->whereNotNull('validated_at')->count());

        // Una bozza nata DOPO l'anteprima non deve entrare nella conferma:
        // si valida esattamente ciò che è stato mostrato
        $tardiva = $this->postJson("/api/v1/assets/{$pronto}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => '2026-06-05', 'failure_class' => 'C',
        ])->assertCreated()->json('data.id');

        // La conferma rimanda gli id contati in anteprima
        $esito = $this->postJson('/api/v1/vta/valida', [
            'assessment_ids' => array_column($prova['validate'], 'id'),
        ])->assertOk()->json('data');
        $this->assertCount(1, $esito['validate']);
        $this->assertNotNull($esito['validate'][0]['protocollo']);

        $perizia = \App\Models\TreeAssessment::query()->findOrFail($prova['validate'][0]['id']);
        $this->assertNotNull($perizia->validated_at);
        $this->assertNotNull($perizia->report_number);
        $this->assertNotNull($perizia->content_hash);

        // La bozza senza classe e quella tardiva sono rimaste bozze
        $this->assertNull(\App\Models\TreeAssessment::query()
            ->where('tree_id', $senzaClasse)->firstOrFail()->validated_at);
        $this->assertNull(\App\Models\TreeAssessment::query()->findOrFail($tardiva)->validated_at);

        // Confermare un id non validabile lo riporta fra le saltate
        $inconfermabile = \App\Models\TreeAssessment::query()
            ->where('tree_id', $senzaClasse)->firstOrFail()->id;
        $riprova = $this->postJson('/api/v1/vta/valida', [
            'assessment_ids' => [$inconfermabile],
        ])->assertOk()->json('data');
        $this->assertCount(0, $riprova['validate']);
        $this->assertCount(1, $riprova['saltate']);
    }

    public function test_bulk_validation_by_client_touches_only_that_client(): void
    {
        $altraArea = $this->createArea($this->organization);

        $qui = $this->createTreeAsset();
        $la = $this->postJson('/api/v1/assets', [
            'area_id' => $altraArea->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => 'ALT-0002',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        // Terzo albero dello stesso committente, con bozza ma abbattuto: le
        // azioni collettive seguono la pagina, che gli abbattuti non li mostra
        $abbattuto = $this->postJson('/api/v1/assets', [
            'area_id' => $altraArea->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => 'ALT-0009',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        foreach ([$qui, $la, $abbattuto] as $albero) {
            $this->postJson("/api/v1/assets/{$albero}/assessments", [
                'assessment_type' => 'vta_visual', 'assessed_on' => '2026-06-01', 'failure_class' => 'C',
            ])->assertCreated();
        }
        $this->patchJson("/api/v1/assets/{$abbattuto}", [
            'tree' => ['removed_on' => now('Europe/Rome')->toDateString(), 'removal_reason' => 'abbattimento'],
        ])->assertOk();

        $clienteLa = $altraArea->locality->site->client_id;
        $esito = $this->postJson('/api/v1/vta/valida', ['client_id' => $clienteLa])
            ->assertOk()->json('data');

        $this->assertCount(1, $esito['validate']);
        $this->assertSame('ALT-0002', $esito['validate'][0]['codice']);
        $this->assertNull(\App\Models\TreeAssessment::query()
            ->where('tree_id', $abbattuto)->firstOrFail()->validated_at);
        $this->assertNull(\App\Models\TreeAssessment::query()->where('tree_id', $qui)->firstOrFail()->validated_at);
        $this->assertNotNull(\App\Models\TreeAssessment::query()->where('tree_id', $la)->firstOrFail()->validated_at);

        // Senza permesso di modifica non si valida niente
        $cliente = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $cliente->assignRole('cliente');
        $this->actingAsTenantUser($cliente);
        $this->postJson('/api/v1/vta/valida', ['client_id' => $clienteLa])->assertForbidden();
    }

    public function test_vta_register_csv_lists_every_assessment(): void
    {
        $id = $this->createTreeAsset();
        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Tilia', 'species' => 'Tilia cordata'],
        ])->assertOk();

        // Due valutazioni sullo stesso albero: nel registro escono entrambe
        $prima = $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => '2024-05-10',
            'failure_class' => 'B', 'outcome' => 'monitor',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/assessments/{$prima}/valida")->assertOk();
        $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_instrumental', 'assessed_on' => '2026-06-01',
            'failure_class' => 'C', 'outcome' => 'prescriptions',
            'prescriptions' => 'Potatura di alleggerimento',
        ])->assertCreated();

        $risposta = $this->post('/api/v1/vta/registro', ['asset_ids' => [$id]])->assertOk();
        $csv = $risposta->streamedContent();

        $this->assertStringContainsString('Codice albero', $csv);
        $this->assertStringContainsString('Tilia cordata', $csv);
        $this->assertStringContainsString('10/05/2024', $csv);
        $this->assertStringContainsString('01/06/2026', $csv);
        $this->assertStringContainsString('Validata', $csv);
        $this->assertStringContainsString('Bozza', $csv);
        $this->assertStringContainsString('VTA strumentale', $csv);
        $this->assertStringContainsString('Potatura di alleggerimento', $csv);
        // Due righe di dati piu' l'intestazione
        $this->assertCount(3, array_filter(explode("\n", trim($csv))));

        // Per committente: stesso registro passando dal cliente dell'area
        $cliente = $this->area->locality->site->client_id;
        $perCliente = $this->post('/api/v1/vta/registro', ['client_id' => $cliente])->assertOk();
        $this->assertStringContainsString('Tilia cordata', $perCliente->streamedContent());

        // Senza selezione né committente la richiesta non parte
        $this->postJson('/api/v1/vta/registro', [])->assertUnprocessable();
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

    public function test_correcting_the_class_recomputes_the_recheck_date(): void
    {
        $id = $this->createTreeAsset();
        $assessment = $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => '2026-06-01',
            'failure_class' => 'C',
        ])->assertCreated()->json('data');
        // Classe C: ricontrollo a 24 mesi
        $this->assertStringStartsWith('2028-06-01', $assessment['next_check_due']);

        // Classe peggiorata a C/D (12 mesi): la scadenza si accorcia da sola,
        // come promette l'etichetta "vuoto = automatico dalla classe"
        $corretta = $this->patchJson("/api/v1/assessments/{$assessment['id']}", [
            'failure_class' => 'C/D',
            'next_check_due' => null,
        ])->assertOk()->json('data');
        $this->assertStringStartsWith('2027-06-01', $corretta['next_check_due']);

        // Una data messa a mano resta quella
        $manuale = $this->patchJson("/api/v1/assessments/{$assessment['id']}", [
            'next_check_due' => '2026-12-31',
        ])->assertOk()->json('data');
        $this->assertStringStartsWith('2026-12-31', $manuale['next_check_due']);

        // Una correzione che non nomina il campo non lo tocca
        $altro = $this->patchJson("/api/v1/assessments/{$assessment['id']}", [
            'prescriptions' => 'Potatura di rimonda del secco.',
        ])->assertOk()->json('data');
        $this->assertStringStartsWith('2026-12-31', $altro['next_check_due']);
    }

    public function test_a_save_without_changes_keeps_the_issued_protocol(): void
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
        $numero = \App\Models\TreeAssessment::query()->findOrFail($assessment['id'])->report_number;
        $this->assertNotNull($numero);

        // Un altro utente riapre la correzione e salva senza cambiare nulla:
        // il numero già consegnato al committente non si butta via
        [, $altroUtente] = $this->createTenantUser();
        $stesso = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $stesso->assignRole('tecnico');
        $this->actingAsTenantUser($stesso);

        $this->patchJson("/api/v1/assessments/{$assessment['id']}", [
            'failure_class' => 'C',
            'assessed_on' => '2026-06-01',
        ])->assertOk();

        $dopo = \App\Models\TreeAssessment::query()->findOrFail($assessment['id']);
        $this->assertSame($numero, $dopo->report_number);
        $this->assertNotNull($dopo->report_issued_at);
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
