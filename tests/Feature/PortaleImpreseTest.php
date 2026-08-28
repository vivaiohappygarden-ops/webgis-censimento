<?php

namespace Tests\Feature;

use App\Models\RescheduleRequest;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Portale delle imprese appaltatrici: la ditta vede solo gli ordini delle
 * sue squadre, con visibilita' a tempo, e chiede formalmente la
 * riprogrammazione con motivo codificato. Il gestionale decide.
 */
class PortaleImpreseTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $amministratore;

    private $area;

    private $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->amministratore] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        // Il committente dell'area di prova: le imprese esterne gli appartengono
        $this->cliente = \App\Models\Client::withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)->firstOrFail();
        $this->actingAsTenantUser($this->amministratore);
    }

    /** @return array{0: string, 1: User} id squadra e utente dell'impresa */
    private function creaImpresa(string $nome = 'Verde Vivo snc'): array
    {
        $teamId = $this->postJson('/api/v1/teams', [
            'name' => $nome, 'is_external' => true, 'client_id' => $this->cliente->id,
        ])->assertCreated()->json('data.id');

        $utenteId = $this->postJson('/api/v1/users', [
            'name' => 'Referente '.$nome,
            'email' => str_replace(' ', '', strtolower($nome)).'@example.com',
            'role' => 'impresa',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/teams/{$teamId}", ['member_ids' => [$utenteId]])->assertOk();

        return [$teamId, User::withoutGlobalScopes()->findOrFail($utenteId)];
    }

    private function creaOrdine(string $teamId, string $stato = 'planned', array $campi = []): string
    {
        $id = $this->postJson('/api/v1/work-orders', array_merge([
            'title' => 'Potatura viale', 'area_id' => $this->area->id, 'team_id' => $teamId,
            'client_id' => $this->cliente->id,
            'planned_start' => '2026-09-07', 'planned_end' => '2026-09-11',
        ], $campi))->assertCreated()->json('data.id');

        $percorso = ['planned' => ['planned'], 'assigned' => ['planned', 'assigned'],
            'completed' => ['planned', 'assigned', 'in_progress', 'completed']][$stato] ?? [];
        foreach ($percorso as $passo) {
            $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => $passo])->assertOk();
        }

        return $id;
    }

    public function test_l_impresa_vede_solo_gli_ordini_delle_sue_squadre(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        [$altroTeam] = $this->creaImpresa('Altra Ditta srl');

        $mio = $this->creaOrdine($teamId);
        $altrui = $this->creaOrdine($altroTeam);
        $bozza = $this->postJson('/api/v1/work-orders', [
            'title' => 'Bozza non visibile', 'area_id' => $this->area->id, 'team_id' => $teamId,
            'client_id' => $this->cliente->id,
        ])->assertCreated()->json('data.id');

        $this->actingAsTenantUser($utente);
        $risposta = $this->getJson('/api/v1/impresa/ordini')->assertOk();
        $ids = collect($risposta->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mio));
        $this->assertFalse($ids->contains($altrui), 'Gli ordini di un\'altra impresa non si vedono');
        $this->assertFalse($ids->contains($bozza), 'Le bozze non si vedono');
        // La risposta porta anche i motivi codificati per la tendina
        $this->assertArrayHasKey('maltempo', $risposta->json('motivi'));
    }

    public function test_i_completati_escono_dopo_trenta_giorni(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        $recente = $this->creaOrdine($teamId, 'completed');
        $vecchio = $this->creaOrdine($teamId, 'completed');
        WorkOrder::withoutGlobalScopes()->whereKey($vecchio)
            ->update(['completed_at' => now()->subDays(45)]);

        $this->actingAsTenantUser($utente);
        $ids = collect($this->getJson('/api/v1/impresa/ordini')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($recente));
        $this->assertFalse($ids->contains($vecchio), 'Un completato vecchio esce dall\'elenco');
    }

    public function test_la_richiesta_si_registra_e_una_sola_resta_aperta(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        $ordine = $this->creaOrdine($teamId);

        $this->actingAsTenantUser($utente);
        $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", [
            'reason' => 'maltempo', 'proposed_start' => now()->addDays(10)->toDateString(),
            'notes' => 'Allerta meteo per tutta la settimana',
        ])->assertCreated();

        // La seconda, con la prima ancora aperta, viene respinta
        $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", [
            'reason' => 'mezzi',
        ])->assertStatus(422);

        // E il motivo inventato pure
        $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", [
            'reason' => 'non-mi-va',
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_l_accettazione_sposta_le_date_e_conserva_la_durata(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        $ordine = $this->creaOrdine($teamId);   // dal 07/09 all'11/09: 4 giorni

        $this->actingAsTenantUser($utente);
        $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", [
            'reason' => 'maltempo', 'proposed_start' => '2026-09-14',
        ])->assertCreated();

        $this->actingAsTenantUser($this->amministratore);
        $richiesta = $this->getJson('/api/v1/riprogrammazioni?stato=aperta')->assertOk()->json('data.0');
        $this->assertSame('Maltempo', $richiesta['motivo']);

        $this->postJson("/api/v1/riprogrammazioni/{$richiesta['id']}/decidi", [
            'esito' => 'accettata', 'response_note' => 'Va bene, ci vediamo lunedì.',
        ])->assertOk();

        $aggiornato = WorkOrder::withoutGlobalScopes()->findOrFail($ordine);
        $this->assertSame('2026-09-14', $aggiornato->planned_start->toDateString());
        $this->assertSame('2026-09-18', $aggiornato->planned_end->toDateString());

        // L'impresa rilegge l'esito con la risposta
        $this->actingAsTenantUser($utente);
        $dati = $this->getJson('/api/v1/impresa/ordini')->assertOk()->json('data.0');
        $this->assertSame('accettata', $dati['richieste'][0]['status']);
        $this->assertSame('Va bene, ci vediamo lunedì.', $dati['richieste'][0]['response_note']);
        // E una richiesta gia' decisa non si decide due volte
        $this->actingAsTenantUser($this->amministratore);
        $this->postJson("/api/v1/riprogrammazioni/{$richiesta['id']}/decidi", ['esito' => 'rifiutata'])
            ->assertStatus(409);
    }

    public function test_un_ordine_chiuso_nel_frattempo_non_si_riprogramma(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        $ordine = $this->creaOrdine($teamId);

        $this->actingAsTenantUser($utente);
        $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", [
            'reason' => 'maltempo', 'proposed_start' => '2026-09-14',
        ])->assertCreated();

        // L'ordine si chiude prima della decisione
        $this->actingAsTenantUser($this->amministratore);
        foreach (['assigned', 'in_progress', 'completed'] as $passo) {
            $this->postJson("/api/v1/work-orders/{$ordine}/transition", ['status' => $passo])->assertOk();
        }

        $id = RescheduleRequest::query()->firstOrFail()->id;
        // Accettare non si può: la storia di un ordine chiuso non si riscrive
        $this->postJson("/api/v1/riprogrammazioni/{$id}/decidi", ['esito' => 'accettata'])
            ->assertStatus(409);
        $dopo = WorkOrder::withoutGlobalScopes()->findOrFail($ordine);
        $this->assertSame('2026-09-07', $dopo->planned_start->toDateString());

        // Non accogliere invece sì, con la risposta per l'impresa
        $this->postJson("/api/v1/riprogrammazioni/{$id}/decidi", [
            'esito' => 'rifiutata', 'response_note' => 'Il lavoro è già stato eseguito.',
        ])->assertOk();
    }

    public function test_con_la_sola_data_di_fine_il_periodo_non_si_capovolge(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        // Ordine con la sola fine prevista: la validazione lo ammette
        $ordine = $this->creaOrdine($teamId, 'planned', [
            'planned_start' => null, 'planned_end' => '2026-09-10',
        ]);

        $this->actingAsTenantUser($utente);
        $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", [
            'reason' => 'mezzi', 'proposed_start' => '2026-09-20',
        ])->assertCreated();

        $this->actingAsTenantUser($this->amministratore);
        $id = RescheduleRequest::query()->firstOrFail()->id;
        $this->postJson("/api/v1/riprogrammazioni/{$id}/decidi", ['esito' => 'accettata'])->assertOk();

        $dopo = WorkOrder::withoutGlobalScopes()->findOrFail($ordine);
        $this->assertSame('2026-09-20', $dopo->planned_start->toDateString());
        // La fine non precede mai l'inizio
        $this->assertSame('2026-09-20', $dopo->planned_end->toDateString());
    }

    public function test_l_esito_arriva_anche_se_l_ordine_esce_dall_elenco(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        $ordine = $this->creaOrdine($teamId);

        $this->actingAsTenantUser($utente);
        $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", [
            'reason' => 'accesso',
        ])->assertCreated();

        // L'ordine viene annullato: sparisce dall'elenco dell'impresa
        $this->actingAsTenantUser($this->amministratore);
        $this->postJson("/api/v1/work-orders/{$ordine}/transition", ['status' => 'cancelled'])->assertOk();
        $id = RescheduleRequest::query()->firstOrFail()->id;
        $this->postJson("/api/v1/riprogrammazioni/{$id}/decidi", [
            'esito' => 'rifiutata', 'response_note' => 'Il lavoro è stato annullato dal committente.',
        ])->assertOk();

        // Ma la richiesta e la risposta restano leggibili, fuori elenco
        $this->actingAsTenantUser($utente);
        $risposta = $this->getJson('/api/v1/impresa/ordini')->assertOk();
        $this->assertCount(0, $risposta->json('data'));
        $fuori = $risposta->json('fuori_elenco.0');
        $this->assertSame('rifiutata', $fuori['status']);
        $this->assertSame('Il lavoro è stato annullato dal committente.', $fuori['response_note']);
        $this->assertNotEmpty($fuori['ordine_code']);
    }

    public function test_il_rifiuto_non_tocca_le_date(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        $ordine = $this->creaOrdine($teamId);

        $this->actingAsTenantUser($utente);
        $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", [
            'reason' => 'personale', 'proposed_start' => '2026-10-01',
        ])->assertCreated();

        $this->actingAsTenantUser($this->amministratore);
        $id = RescheduleRequest::query()->firstOrFail()->id;
        $this->postJson("/api/v1/riprogrammazioni/{$id}/decidi", [
            'esito' => 'rifiutata', 'response_note' => 'La data è vincolata dal committente.',
        ])->assertOk();

        $ordineDb = WorkOrder::withoutGlobalScopes()->findOrFail($ordine);
        $this->assertSame('2026-09-07', $ordineDb->planned_start->toDateString());
    }

    public function test_i_permessi_recintano_il_portale(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        $ordine = $this->creaOrdine($teamId);

        // L'impresa non legge il censimento ne' decide le richieste
        $this->actingAsTenantUser($utente);
        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->getJson('/api/v1/riprogrammazioni')->assertForbidden();
        // E all'accesso atterra sul suo portale
        $this->get('/')->assertRedirect(route('impresa'));

        // L'amministratore ha tutti i permessi e puo' aprire il portale,
        // ma non essendo membro di squadre esterne non vi vede ordini
        $this->actingAsTenantUser($this->amministratore);
        $this->getJson('/api/v1/impresa/ordini')->assertOk()->assertJsonCount(0, 'data');

        // Un'impresa di un'altra organizzazione non vede niente di questo tenant
        [, $estraneo] = $this->createTenantUser();
        $this->actingAsTenantUser($estraneo);
        // Per lui quell'ordine non esiste proprio
        $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", ['reason' => 'maltempo'])
            ->assertNotFound();
    }

    public function test_un_impresa_esterna_appartiene_a_un_committente(): void
    {
        // Senza committente non si registra
        $this->postJson('/api/v1/teams', ['name' => 'Senza Padrone srl', 'is_external' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['client_id']);

        // A una squadra interna il committente non si attacca
        $interna = $this->postJson('/api/v1/teams', [
            'name' => 'Squadra interna', 'client_id' => $this->cliente->id,
        ])->assertCreated()->json('data');
        $this->assertNull($interna['client_id']);

        // L'impresa nasce con il suo committente e lo mostra
        [$teamId] = $this->creaImpresa('Con Padrone snc');
        $this->assertSame($this->cliente->name,
            collect($this->getJson('/api/v1/teams')->json('data'))
                ->firstWhere('id', $teamId)['client']['name']);

        // Togliendo la spunta "esterna", il committente si azzera
        $spenta = $this->patchJson("/api/v1/teams/{$teamId}", ['is_external' => false])
            ->assertOk()->json('data');
        $this->assertNull($spenta['client_id']);
    }

    public function test_a_un_impresa_si_affidano_solo_ordini_del_suo_committente(): void
    {
        [$teamId] = $this->creaImpresa();
        $altro = \App\Models\Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Altro Comune', 'client_type' => 'public',
        ]);

        // Ordine di un altro committente: respinto
        $this->postJson('/api/v1/work-orders', [
            'title' => 'Sfalcio abusivo', 'team_id' => $teamId, 'client_id' => $altro->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['team_id']);

        // Ordine senza committente: idem
        $this->postJson('/api/v1/work-orders', [
            'title' => 'Sfalcio orfano', 'team_id' => $teamId,
        ])->assertUnprocessable()->assertJsonValidationErrors(['team_id']);

        // Ordine del suo committente: va
        $ordine = $this->creaOrdine($teamId);

        // E nemmeno cambiando committente a posteriori si scavalca la regola
        $this->patchJson("/api/v1/work-orders/{$ordine}", ['client_id' => $altro->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['team_id']);
    }

    public function test_un_impresa_d_epoca_senza_committente_va_prima_collegata(): void
    {
        // Squadra esterna nata prima della regola (scritta direttamente in base)
        $vecchia = \App\Models\Team::create([
            'tenant_id' => $this->organizzazione->id,
            'name' => 'Vecchia Ditta', 'is_external' => true,
        ]);

        $risposta = $this->postJson('/api/v1/work-orders', [
            'title' => 'Potatura', 'team_id' => $vecchia->id, 'client_id' => $this->cliente->id,
        ])->assertUnprocessable();
        $this->assertStringContainsString('collegala', $risposta->json('errors.team_id.0'));

        // I membri restano comunque gestibili (la si sistema con calma)
        $this->patchJson("/api/v1/teams/{$vecchia->id}", ['member_ids' => []])->assertOk();
    }

    public function test_il_committente_di_un_impresa_non_cambia_con_ordini_aperti(): void
    {
        [$teamId] = $this->creaImpresa();
        $ordine = $this->creaOrdine($teamId);
        $altro = \App\Models\Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Altro Comune', 'client_type' => 'public',
        ]);

        // Con un ordine ancora aperto il cambio viene respinto
        $this->patchJson("/api/v1/teams/{$teamId}", ['client_id' => $altro->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['client_id']);

        // Chiuso l'ordine, il cambio passa
        foreach (['assigned', 'in_progress', 'completed'] as $passo) {
            $this->postJson("/api/v1/work-orders/{$ordine}/transition", ['status' => $passo])->assertOk();
        }
        $this->patchJson("/api/v1/teams/{$teamId}", ['client_id' => $altro->id])->assertOk();
    }

    public function test_il_portale_non_espone_ordini_di_un_altro_committente(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        $ordine = $this->creaOrdine($teamId);

        // Stato d'epoca forzato in base: la squadra risulta di un altro
        $altro = \App\Models\Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Altro Comune', 'client_type' => 'public',
        ]);
        \App\Models\Team::withoutGlobalScopes()->whereKey($teamId)->update(['client_id' => $altro->id]);

        // L'impresa non vede piu' l'ordine del vecchio committente e non
        // puo' nemmeno chiederne la riprogrammazione
        $this->actingAsTenantUser($utente);
        $this->getJson('/api/v1/impresa/ordini')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", ['reason' => 'maltempo'])
            ->assertNotFound();
    }

    public function test_un_committente_con_imprese_collegate_non_si_elimina(): void
    {
        // Un committente senza sedi ne' altro: solo l'impresa lo trattiene
        $solo = \App\Models\Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Solo Impresa', 'client_type' => 'private',
        ]);
        $teamId = $this->postJson('/api/v1/teams', [
            'name' => 'Ditta del Solo', 'is_external' => true, 'client_id' => $solo->id,
        ])->assertCreated()->json('data.id');

        $risposta = $this->deleteJson("/api/v1/clients/{$solo->id}")->assertUnprocessable();
        $this->assertStringContainsString('impresa esterna collegata', $risposta->json('errors.client.0'));

        // Scollegata l'impresa, l'eliminazione riparte
        $this->patchJson("/api/v1/teams/{$teamId}", ['is_external' => false])->assertOk();
        $this->deleteJson("/api/v1/clients/{$solo->id}")->assertNoContent();
    }

    public function test_un_ordine_d_epoca_fuori_regola_resta_ritoccabile(): void
    {
        // Stato d'epoca: squadra esterna senza committente con ordine assegnato
        $vecchia = \App\Models\Team::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Vecchia Ditta', 'is_external' => true,
        ]);
        $ordine = \App\Models\WorkOrder::create([
            'tenant_id' => $this->organizzazione->id, 'code' => 'ODL-2026-9001',
            'title' => 'Ordine d\'epoca', 'status' => 'planned',
            'team_id' => $vecchia->id, 'client_id' => $this->cliente->id,
        ]);

        // Date e descrizione si ritoccano ancora
        $this->patchJson("/api/v1/work-orders/{$ordine->id}", ['description' => 'nota aggiunta'])
            ->assertOk();

        // Ma un cambio di committente deve approdare in regola
        $altro = \App\Models\Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Altro Comune', 'client_type' => 'public',
        ]);
        $this->patchJson("/api/v1/work-orders/{$ordine->id}", ['client_id' => $altro->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['team_id']);
    }

    public function test_l_impresa_di_un_committente_eliminato_da_un_errore_parlante(): void
    {
        // Stato d'epoca: il committente sparisce da sotto l'impresa
        // (oggi destroy lo impedisce; il dato vecchio pero' puo' esistere)
        $solo = \App\Models\Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Sparito', 'client_type' => 'private',
        ]);
        $teamId = $this->postJson('/api/v1/teams', [
            'name' => 'Ditta Orfana', 'is_external' => true, 'client_id' => $solo->id,
        ])->assertCreated()->json('data.id');
        $solo->delete();

        $risposta = $this->postJson('/api/v1/work-orders', [
            'title' => 'Potatura', 'team_id' => $teamId, 'client_id' => $this->cliente->id,
        ])->assertUnprocessable();
        $this->assertStringContainsString('committente eliminato', $risposta->json('errors.team_id.0'));
    }

    public function test_il_correttivo_non_copia_una_squadra_non_piu_ammessa(): void
    {
        [$teamId] = $this->creaImpresa();
        $ordine = $this->creaOrdine($teamId, 'completed');
        // Stato d'epoca: la squadra risulta ora di un altro committente
        $altro = \App\Models\Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Altro Comune', 'client_type' => 'public',
        ]);
        \App\Models\Team::withoutGlobalScopes()->whereKey($teamId)->update(['client_id' => $altro->id]);

        $this->postJson("/api/v1/work-orders/{$ordine}/checks", [
            'outcome' => 'failed',
            'non_conformity' => [
                'severity' => 'major',
                'description' => 'Lavoro da rifare lungo il lato nord.',
                'create_corrective_order' => true,
            ],
        ])->assertOk();

        $correttivo = \App\Models\WorkOrder::query()->where('origin', 'non_conformity')->firstOrFail();
        $this->assertNull($correttivo->team_id, 'La squadra non piu\' ammessa non si copia');
        $this->assertStringContainsString('da riassegnare', $correttivo->description);
    }

    public function test_l_impresa_ha_la_sua_porta_d_ingresso(): void
    {
        [, $utente] = $this->creaImpresa();
        $utente->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('segretissima1')])->save();
        // Si torna ospiti: via l'utente fissato da Sanctum e si riparte
        // dalla guardia di sessione, come un browser appena aperto
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        // Da ospite, il portale rimanda alla porta dedicata (non al login
        // del gestionale) e la pagina risponde
        $this->get('/impresa')->assertRedirect(route('impresa.login'));
        $this->get('/impresa/login')->assertOk();

        // La serratura è la stessa: dall'ingresso dedicato si entra e si
        // atterra dritti sui lavori affidati
        $this->post('/login', ['email' => $utente->email, 'password' => 'segretissima1'])
            ->assertRedirect(route('impresa'));
    }

    public function test_una_squadra_interna_non_apre_il_portale(): void
    {
        // Squadra NON esterna: anche se l'utente impresa ne fa parte,
        // il portale non mostra i suoi ordini
        $teamId = $this->postJson('/api/v1/teams', ['name' => 'Squadra interna'])
            ->assertCreated()->json('data.id');
        $utenteId = $this->postJson('/api/v1/users', [
            'name' => 'X', 'email' => 'interna@example.com', 'role' => 'impresa',
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/teams/{$teamId}", ['member_ids' => [$utenteId]])->assertOk();
        $this->creaOrdine($teamId);

        $this->actingAsTenantUser(User::withoutGlobalScopes()->findOrFail($utenteId));
        $this->getJson('/api/v1/impresa/ordini')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
