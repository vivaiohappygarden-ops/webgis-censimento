<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Client;
use App\Models\Locality;
use App\Models\MaintenancePlan;
use App\Models\Organization;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Support\Geometry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Piani di manutenzione pluriennali: la ricorrenza parte dall'ultimo ordine
 * esistente (di qualunque origine), le scadenze slittano dentro la finestra
 * stagionale, la generazione e' idempotente (chiave plan_month sul DB) e
 * l'anteprima conta esattamente quello che la conferma crea, perche' passano
 * dallo stesso metodo.
 */
class PianiManutenzioneTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organizzazione;

    private User $utente;

    private Client $cliente;

    private Area $area;

    private WorkType $potatura;

    private WorkType $sfalcio;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->actingAsTenantUser($this->utente);

        $this->cliente = Client::create([
            'tenant_id' => $this->organizzazione->id,
            'name' => 'Comune di Mentana', 'client_type' => 'public',
        ]);
        $this->area = $this->areaDelCliente($this->cliente, 'Parco Nord');

        $this->potatura = WorkType::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => 'POT', 'name' => 'Potatura', 'unit' => 'cad',
        ]);
        $this->sfalcio = WorkType::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => 'SFA', 'name' => 'Sfalcio', 'unit' => 'mq',
        ]);
    }

    /** Catena completa committente > sede > localita' > area, per la derivazione. */
    private function areaDelCliente(Client $cliente, string $nome, array $attributi = []): Area
    {
        $sede = Site::create([
            'tenant_id' => $this->organizzazione->id, 'client_id' => $cliente->id, 'name' => "Sede {$nome}",
        ]);
        $localita = Locality::create([
            'tenant_id' => $this->organizzazione->id, 'site_id' => $sede->id, 'name' => "Localita {$nome}",
        ]);

        return Area::create([
            'tenant_id' => $this->organizzazione->id,
            'locality_id' => $localita->id,
            'name' => $nome,
            'geom' => Geometry::toEwkb($this->squarePolygon(), forceMultiPolygon: true),
            ...$attributi,
        ]);
    }

    private function creaPiano(array $campi = []): MaintenancePlan
    {
        return MaintenancePlan::create(array_merge([
            'tenant_id' => $this->organizzazione->id,
            'area_id' => $this->area->id,
            'work_type_id' => $this->potatura->id,
            'interval_months' => 36,
            'is_active' => true,
        ], $campi));
    }

    /** Un ordine esistente su area e lavorazione, riferimento della ricorrenza. */
    private function ordineEsistente(string $plannedStart, array $campi = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'tenant_id' => $this->organizzazione->id,
            'code' => 'ODL-MAN-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'Ordine esistente',
            'status' => 'completed',
            'client_id' => $this->cliente->id,
            'area_id' => $this->area->id,
            'work_type_id' => $this->potatura->id,
            'planned_start' => $plannedStart,
        ], $campi));
    }

    private function genera(string $da, string $a, bool $prova = false): array
    {
        return $this->postJson('/api/v1/piani-manutenzione/genera', [
            'da' => $da, 'a' => $a, ...($prova ? ['prova' => 1] : []),
        ])->assertOk()->json('data');
    }

    // --- Ricorrenza dall'ultima volta ---------------------------------------

    public function test_ogni_36_mesi_con_ultimo_ordine_2_anni_fa_niente_nel_periodo(): void
    {
        $this->creaPiano();
        $this->ordineEsistente('2024-06-10');

        // Due anni dopo: la prossima potatura cade nel 2027-06, fuori periodo
        $esito = $this->genera('2026-06', '2027-05');

        $this->assertCount(0, $esito['creati']);
        $this->assertCount(1, $esito['saltati']);
        $this->assertStringContainsString('Nessuna scadenza nel periodo', $esito['saltati'][0]['motivo']);
        $this->assertStringContainsString('2027-06', $esito['saltati'][0]['motivo']);
    }

    public function test_a_3_anni_dal_riferimento_un_ordine(): void
    {
        $this->creaPiano();
        $this->ordineEsistente('2024-06-10');

        $esito = $this->genera('2027-01', '2027-12');

        $this->assertCount(1, $esito['creati']);
        $this->assertSame('2027-06', $esito['creati'][0]['mese']);

        $ordine = WorkOrder::query()->where('origin', 'maintenance_plan')->sole();
        $this->assertSame('2027-06-01', $ordine->plan_month->toDateString());
        $this->assertSame('2027-06-01', $ordine->planned_start->toDateString());
        $this->assertSame('planned', $ordine->status);
        $this->assertSame($this->cliente->id, $ordine->client_id);
        $this->assertSame($this->area->id, $ordine->area_id);
        $this->assertSame('Potatura - Parco Nord (dal piano)', $ordine->title);
        // Il riferimento vale per QUALUNQUE origine: quello sopra era manuale
    }

    public function test_ordine_annullato_non_fa_da_riferimento(): void
    {
        $this->creaPiano();
        $this->ordineEsistente('2024-06-10', ['status' => 'cancelled', 'code' => 'ODL-ANN-1']);

        // Senza riferimenti validi si parte dalla prima occasione del periodo
        $esito = $this->genera('2027-01', '2027-03');

        $this->assertCount(1, $esito['creati']);
        $this->assertSame('2027-01', $esito['creati'][0]['mese']);
    }

    public function test_sfalcio_mensile_marzo_ottobre_su_un_anno_8_ordini(): void
    {
        $this->creaPiano([
            'work_type_id' => $this->sfalcio->id,
            'interval_months' => 1, 'month_from' => 3, 'month_to' => 10,
        ]);

        $esito = $this->genera('2027-01', '2027-12');

        $this->assertCount(8, $esito['creati']);
        $this->assertSame(
            ['2027-03', '2027-04', '2027-05', '2027-06', '2027-07', '2027-08', '2027-09', '2027-10'],
            array_column($esito['creati'], 'mese'),
        );
        $this->assertSame(8, WorkOrder::query()->where('origin', 'maintenance_plan')->count());
    }

    public function test_finestra_a_cavallo_del_capodanno(): void
    {
        // Potatura invernale: da novembre a febbraio
        $this->creaPiano(['interval_months' => 1, 'month_from' => 11, 'month_to' => 2]);

        $esito = $this->genera('2027-01', '2027-12');

        $this->assertSame(
            ['2027-01', '2027-02', '2027-11', '2027-12'],
            array_column($esito['creati'], 'mese'),
        );
    }

    // --- Idempotenza ---------------------------------------------------------

    public function test_doppia_generazione_zero_doppioni(): void
    {
        $this->creaPiano([
            'work_type_id' => $this->sfalcio->id,
            'interval_months' => 1, 'month_from' => 3, 'month_to' => 10,
        ]);

        $primo = $this->genera('2027-01', '2027-12');
        $secondo = $this->genera('2027-01', '2027-12');

        $this->assertCount(8, $primo['creati']);
        $this->assertCount(0, $secondo['creati']);
        $this->assertSame(8, WorkOrder::query()->where('origin', 'maintenance_plan')->count());
    }

    public function test_ordine_spostato_in_agenda_non_si_rigenera(): void
    {
        $piano = $this->creaPiano([
            'work_type_id' => $this->sfalcio->id,
            'interval_months' => 1, 'month_from' => 3, 'month_to' => 10,
        ]);
        $this->genera('2027-01', '2027-12');

        // In agenda lo sfalcio di ottobre viene anticipato a maggio: adesso
        // l'ultimo lavoro "visto" e' quello di settembre e la griglia
        // riproporrebbe ottobre. La scadenza coperta pero' resta scritta in
        // plan_month, e ottobre non si rigenera
        WorkOrder::query()->where('origin', 'maintenance_plan')
            ->whereDate('plan_month', '2027-10-01')
            ->update(['planned_start' => '2027-05-20']);

        $esito = $this->genera('2027-01', '2027-12');

        $this->assertCount(0, $esito['creati']);
        $motivi = array_column($esito['saltati'], 'motivo');
        $this->assertNotEmpty(array_filter($motivi, fn ($m) => str_contains($m, "Gia' generato")));
        $this->assertSame(8, WorkOrder::query()->where('origin_id', $piano->id)->count());
    }

    public function test_prova_non_scrive_e_conta_come_l_esecuzione(): void
    {
        $this->creaPiano([
            'work_type_id' => $this->sfalcio->id,
            'interval_months' => 1, 'month_from' => 3, 'month_to' => 10,
        ]);

        $anteprima = $this->genera('2027-01', '2027-12', prova: true);

        $this->assertCount(8, $anteprima['creati']);
        $this->assertSame(0, WorkOrder::query()->where('origin', 'maintenance_plan')->count());
        $this->assertNull($anteprima['creati'][0]['codice']);

        $esecuzione = $this->genera('2027-01', '2027-12');

        // Stesso metodo, stessi conteggi: cambia solo il codice assegnato
        $senzaCodice = fn ($righe) => array_map(
            fn ($r) => collect($r)->except('codice')->all(), $righe,
        );
        $this->assertSame($senzaCodice($anteprima['creati']), $senzaCodice($esecuzione['creati']));
        $this->assertSame($anteprima['saltati'], $esecuzione['saltati']);
        $this->assertSame(8, WorkOrder::query()->where('origin', 'maintenance_plan')->count());
    }

    // --- Aree non lavorabili -------------------------------------------------

    public function test_area_dismessa_o_eliminata_non_genera(): void
    {
        $dismessa = $this->areaDelCliente($this->cliente, 'Area dismessa', ['status' => 'dismissed']);
        $eliminata = $this->areaDelCliente($this->cliente, 'Area eliminata');
        $this->creaPiano(['area_id' => $dismessa->id]);
        $this->creaPiano(['area_id' => $eliminata->id, 'work_type_id' => $this->sfalcio->id]);
        $eliminata->delete();

        $esito = $this->genera('2027-01', '2027-12');

        $this->assertCount(0, $esito['creati']);
        $motivi = array_column($esito['saltati'], 'motivo');
        sort($motivi);
        $this->assertStringContainsString('Area dismessa', $motivi[0]);
        $this->assertStringContainsString('Area eliminata', $motivi[1]);
    }

    public function test_piano_spento_non_genera(): void
    {
        $this->creaPiano(['is_active' => false]);

        $esito = $this->genera('2027-01', '2027-12');

        // Un piano spento e' una scelta, non un errore: niente creati e
        // nemmeno una voce fra i saltati
        $this->assertCount(0, $esito['creati']);
        $this->assertCount(0, $esito['saltati']);
    }

    // --- Squadra di preferenza -----------------------------------------------

    public function test_squadra_di_altro_committente_rifiutata_alla_creazione(): void
    {
        $altro = Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Altro Comune', 'client_type' => 'public',
        ]);
        $impresaAltrui = Team::create([
            'tenant_id' => $this->organizzazione->id, 'code' => 'IMP-A', 'name' => 'Impresa Altrui',
            'is_external' => true, 'client_id' => $altro->id,
        ]);

        $this->postJson('/api/v1/piani-manutenzione', [
            'area_id' => $this->area->id,
            'work_type_id' => $this->potatura->id,
            'interval_months' => 36,
            'team_id' => $impresaAltrui->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('team_id');

        // L'impresa del committente giusto invece passa
        $impresaGiusta = Team::create([
            'tenant_id' => $this->organizzazione->id, 'code' => 'IMP-G', 'name' => 'Impresa Giusta',
            'is_external' => true, 'client_id' => $this->cliente->id,
        ]);
        $this->postJson('/api/v1/piani-manutenzione', [
            'area_id' => $this->area->id,
            'work_type_id' => $this->potatura->id,
            'interval_months' => 36,
            'team_id' => $impresaGiusta->id,
        ])->assertCreated();
    }

    public function test_squadra_del_piano_finisce_sull_ordine(): void
    {
        $squadra = Team::create([
            'tenant_id' => $this->organizzazione->id, 'code' => 'SQ1', 'name' => 'Squadra Verde',
        ]);
        $this->creaPiano(['team_id' => $squadra->id]);

        $esito = $this->genera('2027-01', '2027-03');

        $this->assertSame('Squadra Verde', $esito['creati'][0]['squadra']);
        $this->assertSame($squadra->id, WorkOrder::query()->where('origin', 'maintenance_plan')->sole()->team_id);
    }

    public function test_squadra_non_piu_ammissibile_ordine_senza_squadra(): void
    {
        // Alla creazione era interna; poi diventa impresa di un altro
        // committente: l'ordine e' dovuto comunque, ma nasce senza squadra
        $squadra = Team::create([
            'tenant_id' => $this->organizzazione->id, 'code' => 'SQ2', 'name' => 'Squadra Cambiata',
        ]);
        $this->creaPiano(['team_id' => $squadra->id]);
        $altro = Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Altro', 'client_type' => 'private',
        ]);
        $squadra->update(['is_external' => true, 'client_id' => $altro->id]);

        $esito = $this->genera('2027-01', '2027-03');

        $this->assertCount(1, $esito['creati']);
        $this->assertNull($esito['creati'][0]['squadra']);
        $this->assertStringContainsString('non ammissibile', $esito['creati'][0]['avvertenza']);
        $this->assertNull(WorkOrder::query()->where('origin', 'maintenance_plan')->sole()->team_id);
    }

    // --- CRUD e validazioni --------------------------------------------------

    public function test_niente_due_piani_per_stessa_area_e_lavorazione(): void
    {
        $this->creaPiano();

        $this->postJson('/api/v1/piani-manutenzione', [
            'area_id' => $this->area->id,
            'work_type_id' => $this->potatura->id,
            'interval_months' => 12,
        ])->assertUnprocessable()->assertJsonValidationErrors('work_type_id');
    }

    public function test_finestra_a_meta_rifiutata(): void
    {
        $this->postJson('/api/v1/piani-manutenzione', [
            'area_id' => $this->area->id,
            'work_type_id' => $this->potatura->id,
            'interval_months' => 1,
            'month_from' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors('month_to');
    }

    public function test_finestra_a_meta_rifiutata_anche_in_modifica(): void
    {
        // I required_with guardano solo la richiesta: senza il controllo sui
        // valori finali, questi PATCH passerebbero la validazione e
        // morirebbero sul CHECK del database con un errore grezzo (500)
        $senzaFinestra = $this->creaPiano();
        $this->patchJson("/api/v1/piani-manutenzione/{$senzaFinestra->id}", ['month_from' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('month_to');

        $conFinestra = $this->creaPiano([
            'work_type_id' => $this->sfalcio->id, 'month_from' => 3, 'month_to' => 10,
        ]);
        $this->patchJson("/api/v1/piani-manutenzione/{$conFinestra->id}", ['month_to' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('month_to');

        // Spegnere la finestra per intero invece si puo'
        $this->patchJson("/api/v1/piani-manutenzione/{$conFinestra->id}", [
            'month_from' => null, 'month_to' => null,
        ])->assertOk();
        $this->assertNull($conFinestra->fresh()->month_from);
    }

    public function test_periodo_oltre_12_mesi_rifiutato(): void
    {
        $this->creaPiano();

        $this->postJson('/api/v1/piani-manutenzione/genera', [
            'da' => '2027-01', 'a' => '2028-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('a');
    }

    public function test_indice_filtrabile_per_committente(): void
    {
        $this->creaPiano();
        $altro = Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Altro Comune', 'client_type' => 'public',
        ]);
        $areaAltro = $this->areaDelCliente($altro, 'Giardino Sud');
        $this->creaPiano(['area_id' => $areaAltro->id]);

        $tutti = $this->getJson('/api/v1/piani-manutenzione')->assertOk()->json('data');
        $this->assertCount(2, $tutti);

        $filtrati = $this->getJson('/api/v1/piani-manutenzione?client_id='.$this->cliente->id)
            ->assertOk()->json('data');
        $this->assertCount(1, $filtrati);
        $this->assertSame('Parco Nord', $filtrati[0]['area']['name']);
        // La risposta porta il committente ricavato dalla catena, per l'elenco
        $this->assertSame('Comune di Mentana', $filtrati[0]['area']['locality']['site']['client']['name']);
    }

    // --- Permessi e multi-tenant ---------------------------------------------

    public function test_permessi(): void
    {
        $piano = $this->creaPiano();

        // works.view (operatore) legge ma non scrive
        $operatore = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $operatore->assignRole('operatore');
        $this->actingAsTenantUser($operatore);
        $this->getJson('/api/v1/piani-manutenzione')->assertOk();
        $this->postJson('/api/v1/piani-manutenzione', [])->assertForbidden();
        $this->postJson('/api/v1/piani-manutenzione/genera', ['da' => '2027-01', 'a' => '2027-02'])->assertForbidden();
        $this->patchJson("/api/v1/piani-manutenzione/{$piano->id}", [])->assertForbidden();
        $this->deleteJson("/api/v1/piani-manutenzione/{$piano->id}")->assertForbidden();

        // Il cliente non vede nemmeno l'elenco
        $cliente = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        $cliente->assignRole('cliente');
        $this->actingAsTenantUser($cliente);
        $this->getJson('/api/v1/piani-manutenzione')->assertForbidden();
    }

    public function test_multi_tenant(): void
    {
        $this->creaPiano([
            'work_type_id' => $this->sfalcio->id,
            'interval_months' => 1, 'month_from' => 3, 'month_to' => 10,
        ]);

        // Un piano identico in un altro tenant
        [$altraOrg, $altroUtente] = $this->createTenantUser();
        $this->actingAsTenantUser($altroUtente);
        $altroCliente = Client::create([
            'tenant_id' => $altraOrg->id, 'name' => 'Comune Estraneo', 'client_type' => 'public',
        ]);
        $sede = Site::create(['tenant_id' => $altraOrg->id, 'client_id' => $altroCliente->id, 'name' => 'Sede E']);
        $localita = Locality::create(['tenant_id' => $altraOrg->id, 'site_id' => $sede->id, 'name' => 'Loc E']);
        $areaEstranea = Area::create([
            'tenant_id' => $altraOrg->id, 'locality_id' => $localita->id, 'name' => 'Area Estranea',
            'geom' => Geometry::toEwkb($this->squarePolygon(), forceMultiPolygon: true),
        ]);
        $tipoEstraneo = WorkType::create([
            'tenant_id' => $altraOrg->id, 'code' => 'SFA', 'name' => 'Sfalcio', 'unit' => 'mq',
        ]);
        MaintenancePlan::create([
            'tenant_id' => $altraOrg->id, 'area_id' => $areaEstranea->id,
            'work_type_id' => $tipoEstraneo->id, 'interval_months' => 12, 'is_active' => true,
        ]);

        // L'altro tenant vede solo il suo piano e genera solo i suoi ordini
        $this->assertCount(1, $this->getJson('/api/v1/piani-manutenzione')->assertOk()->json('data'));
        $esito = $this->genera('2027-01', '2027-12');
        $this->assertCount(1, $esito['creati']);
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)->count());
        $this->assertSame(1, WorkOrder::withoutGlobalScopes()
            ->where('tenant_id', $altraOrg->id)->count());
    }
}
