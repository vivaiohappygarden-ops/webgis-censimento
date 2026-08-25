<?php

namespace Tests\Feature;

use App\Models\TreeAssessment;
use App\Services\Trees\PeriziaValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Chiusura della perizia: una volta validata e' un atto e non si tocca piu'.
 *
 * Il blocco sta nel database, non solo nel programma: e' la garanzia che si
 * offre al committente e deve reggere anche a una scrittura fatta da un'altra
 * strada.
 */
class PeriziaValidazioneTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $utente;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();
        [$organizzazione, $this->utente] = $this->createTenantUser();
        $area = $this->createArea($organizzazione);
        $tipoAlbero = $this->makeObjectType($organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);

        $this->assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    private function creaPerizia(array $extra = []): string
    {
        return $this->postJson("/api/v1/assets/{$this->assetId}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => '2026-05-10',
            'failure_class' => 'B',
            'outcome' => 'monitor',
            ...$extra,
        ])->assertCreated()->json('data.id');
    }

    public function test_la_validazione_chiude_la_perizia_e_le_da_un_protocollo(): void
    {
        $id = $this->creaPerizia();

        $risposta = $this->postJson("/api/v1/assessments/{$id}/valida")->assertOk();

        $this->assertNotNull($risposta->json('data.validated_at'));
        $this->assertNotNull($risposta->json('data.report_number'));
        $this->assertNotNull($risposta->json('data.content_hash'));
        $this->assertSame($this->utente->id, $risposta->json('data.validated_by'));
    }

    public function test_una_perizia_validata_non_si_corregge_e_non_si_cancella(): void
    {
        $id = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$id}/valida")->assertOk();

        $this->patchJson("/api/v1/assessments/{$id}", ['prescriptions' => 'nuova prescrizione'])
            ->assertStatus(409);

        $this->deleteJson("/api/v1/assessments/{$id}")->assertStatus(409);

        $this->assertSame(null, TreeAssessment::findOrFail($id)->prescriptions);
    }

    public function test_una_bozza_si_elimina_e_l_eliminazione_resta_registrata(): void
    {
        $id = $this->creaPerizia();

        $this->deleteJson("/api/v1/assessments/{$id}")->assertNoContent();

        // Cancellazione morbida: sparisce dall'app ma la riga resta
        $this->assertSoftDeleted('tree_assessments', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vta.deleted']);
    }

    public function test_l_operatore_non_elimina_nemmeno_le_bozze(): void
    {
        $id = $this->creaPerizia();

        $operatore = \App\Models\User::factory()->create(['tenant_id' => $this->utente->tenant_id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->utente->tenant_id);
        $operatore->assignRole('operatore');
        $this->actingAsTenantUser($operatore);

        $this->deleteJson("/api/v1/assessments/{$id}")->assertForbidden();
    }

    public function test_il_messaggio_spiega_come_si_corregge(): void
    {
        $id = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$id}/valida")->assertOk();

        $this->patchJson("/api/v1/assessments/{$id}", ['outcome' => 'ok'])
            ->assertStatus(409)
            ->assertSee('registra una nuova', false);
    }

    public function test_il_blocco_regge_anche_scavalcando_il_programma(): void
    {
        $id = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$id}/valida")->assertOk();

        // Scrittura diretta sulla tabella: deve fermarla il database.
        // Ogni tentativo sta in una transazione annidata: il primo errore
        // annulla la transazione in corso e senza il punto di ripristino i
        // tentativi successivi fallirebbero per un altro motivo
        foreach ([
            fn () => DB::table('tree_assessments')->where('id', $id)->update(['failure_class' => 'D']),
            fn () => DB::table('tree_assessments')->where('id', $id)->delete(),
            fn () => DB::table('tree_assessments')->where('id', $id)->update(['deleted_at' => now()]),
        ] as $scrittura) {
            try {
                DB::transaction($scrittura);
                $this->fail('La scrittura diretta non e\' stata bloccata dal database.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('validata', $e->getMessage());
            }
        }

        // E la perizia e' ancora quella di prima
        $this->assertSame('B', TreeAssessment::findOrFail($id)->failure_class);
    }

    public function test_la_pubblicazione_sul_portale_resta_possibile(): void
    {
        $id = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$id}/valida")->assertOk();

        // Non e' contenuto tecnico: e' la scelta del committente su cosa mostrare
        DB::table('tree_assessments')->where('id', $id)->update(['is_public' => true]);

        $this->assertTrue(TreeAssessment::findOrFail($id)->is_public);
    }

    public function test_non_si_valida_due_volte(): void
    {
        $id = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$id}/valida")->assertOk();
        $this->postJson("/api/v1/assessments/{$id}/valida")->assertStatus(409);
    }

    public function test_senza_classe_di_propensione_non_si_valida(): void
    {
        $id = $this->creaPerizia(['failure_class' => null]);

        $this->postJson("/api/v1/assessments/{$id}/valida")
            ->assertStatus(422)
            ->assertSee('classe di propensione', false);
    }

    public function test_l_impronta_riconosce_il_contenuto_validato(): void
    {
        $id = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$id}/valida")->assertOk();

        $perizia = TreeAssessment::findOrFail($id);
        $this->assertTrue(PeriziaValidation::integra($perizia));

        // La stessa perizia da' sempre la stessa impronta
        $this->assertSame(
            PeriziaValidation::impronta($perizia),
            PeriziaValidation::impronta(TreeAssessment::findOrFail($id)),
        );
    }

    public function test_la_perizia_in_bozza_si_corregge_ancora(): void
    {
        $id = $this->creaPerizia();

        $this->patchJson("/api/v1/assessments/{$id}", ['prescriptions' => 'potatura di rimonda'])
            ->assertOk();

        $this->assertSame('potatura di rimonda', TreeAssessment::findOrFail($id)->prescriptions);
    }
}
