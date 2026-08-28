<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/** TEMPORANEO: dimostrazioni per la revisione del blocco 12. Da eliminare. */
class RevisioneBlocco12TempTest extends TestCase
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
        $this->cliente = Client::withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)->firstOrFail();
        $this->actingAsTenantUser($this->amministratore);
    }

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
            'in_progress' => ['planned', 'assigned', 'in_progress'],
            'completed' => ['planned', 'assigned', 'in_progress', 'completed']][$stato] ?? [];
        foreach ($percorso as $passo) {
            $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => $passo])->assertOk();
        }

        return $id;
    }

    public function test_1_cambio_committente_squadra_con_ordini_e_portale(): void
    {
        [$teamId, $utente] = $this->creaImpresa();
        $ordine = $this->creaOrdine($teamId, 'assigned');

        $altro = Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Altro Comune', 'client_type' => 'public',
        ]);

        // Il PATCH della squadra passa senza controllare gli ordini esistenti?
        $r = $this->patchJson("/api/v1/teams/{$teamId}", ['client_id' => $altro->id]);
        fwrite(STDERR, "\nPATCH team client: ".$r->status()."\n");
        $r->assertOk();

        // Il portale mostra ancora l'ordine del vecchio committente?
        $this->actingAsTenantUser($utente);
        $ids = collect($this->getJson('/api/v1/impresa/ordini')->assertOk()->json('data'))->pluck('id');
        fwrite(STDERR, 'Portale vede ordine vecchio committente: '.($ids->contains($ordine) ? 'SI' : 'no')."\n");

        // E la riprogrammazione?
        $rr = $this->postJson("/api/v1/impresa/ordini/{$ordine}/riprogrammazione", ['reason' => 'maltempo']);
        fwrite(STDERR, 'Riprogrammazione su ordine vecchio committente: '.$rr->status()."\n");

        // L'ordine resta modificabile dal gestionale?
        $this->actingAsTenantUser($this->amministratore);
        $u = $this->patchJson("/api/v1/work-orders/{$ordine}", ['title' => 'Solo titolo']);
        fwrite(STDERR, 'PATCH titolo ordine dopo cambio committente squadra: '.$u->status().' '.json_encode($u->json('errors'))."\n");
        $this->assertTrue(true);
    }

    public function test_2_ordine_correttivo_scavalca_la_regola(): void
    {
        [$teamId] = $this->creaImpresa('Ditta Controllata');
        $ordine = $this->creaOrdine($teamId, 'in_progress');

        $altro = Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Comune B', 'client_type' => 'public',
        ]);
        $this->patchJson("/api/v1/teams/{$teamId}", ['client_id' => $altro->id])->assertOk();

        $r = $this->postJson("/api/v1/work-orders/{$ordine}/checks", [
            'outcome' => 'failed',
            'non_conformity' => [
                'severity' => 'major',
                'description' => 'Lavoro non conforme',
                'create_corrective_order' => true,
            ],
        ]);
        fwrite(STDERR, "\nCheck con ordine correttivo: ".$r->status()."\n");

        $correttivo = WorkOrder::withoutGlobalScopes()->where('origin', 'non_conformity')->first();
        if ($correttivo) {
            $team = Team::withoutGlobalScopes()->find($correttivo->team_id);
            fwrite(STDERR, 'Correttivo: client='.$correttivo->client_id.' team='.$correttivo->team_id
                .' team.client='.$team?->client_id."\n");
            fwrite(STDERR, 'Viola la regola: '.($correttivo->team_id !== null && $team->client_id !== $correttivo->client_id ? 'SI' : 'no')."\n");
        } else {
            fwrite(STDERR, "Nessun correttivo creato\n");
        }
        $this->assertTrue(true);
    }

    public function test_3_eliminazione_committente_con_impresa_collegata(): void
    {
        $solo = Client::create([
            'tenant_id' => $this->organizzazione->id, 'name' => 'Comune Senza Sedi', 'client_type' => 'public',
        ]);
        $teamId = $this->postJson('/api/v1/teams', [
            'name' => 'Impresa Orfananda', 'is_external' => true, 'client_id' => $solo->id,
        ])->assertCreated()->json('data.id');

        $d = $this->deleteJson("/api/v1/clients/{$solo->id}");
        fwrite(STDERR, "\nDELETE committente con impresa collegata: ".$d->status()."\n");

        // Ora si prova ad affidare un ordine all'impresa orfana
        $r = $this->postJson('/api/v1/work-orders', [
            'title' => 'Ordine post-eliminazione', 'team_id' => $teamId, 'client_id' => $this->cliente->id,
        ]);
        fwrite(STDERR, 'POST ordine con impresa dal committente eliminato: '.$r->status()."\n");
        if ($r->status() >= 500) {
            fwrite(STDERR, 'Messaggio: '.substr((string) $r->json('message'), 0, 200)."\n");
        }
        $this->assertTrue(true);
    }

    public function test_4_ordine_depoca_completato_non_si_pubblica(): void
    {
        $vecchia = Team::create([
            'tenant_id' => $this->organizzazione->id,
            'name' => 'Vecchia Ditta', 'is_external' => true,
        ]);
        $ordine = WorkOrder::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => 'OL-EPOCA-1',
            'title' => 'Lavoro d\'epoca concluso',
            'status' => 'completed',
            'completed_at' => now()->subDays(3),
            'client_id' => $this->cliente->id,
            'team_id' => $vecchia->id,
            'created_by' => $this->amministratore->id,
            'updated_by' => $this->amministratore->id,
        ]);

        $r = $this->patchJson("/api/v1/work-orders/{$ordine->id}", ['is_public' => true]);
        fwrite(STDERR, "\nPubblicazione ordine completato con squadra d'epoca: ".$r->status().' '.json_encode($r->json('errors'))."\n");

        // E una modifica qualsiasi a un ordine d'epoca ancora aperto?
        $aperto = WorkOrder::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => 'OL-EPOCA-2',
            'title' => 'Lavoro d\'epoca aperto',
            'status' => 'planned',
            'client_id' => $this->cliente->id,
            'team_id' => $vecchia->id,
            'created_by' => $this->amministratore->id,
            'updated_by' => $this->amministratore->id,
        ]);
        $r2 = $this->patchJson("/api/v1/work-orders/{$aperto->id}", ['description' => 'aggiorno solo la descrizione']);
        fwrite(STDERR, "Modifica descrizione ordine d'epoca: ".$r2->status().' '.json_encode($r2->json('errors'))."\n");
        $this->assertTrue(true);
    }
}
