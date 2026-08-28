<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Intervalli di ricontrollo VTA regolabili dall'interfaccia.
 *
 * I mesi per classe di propensione al cedimento si cambiano dalla pagina
 * Utenti; il calcolo automatico della data di prossimo controllo li usa per
 * le nuove valutazioni, senza toccare le scadenze gia' assegnate.
 */
class IntervalliRicontrolloTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    private $tipoAlbero;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->tipoAlbero = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);
    }

    private function creaAlbero(): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    public function test_gli_intervalli_predefiniti_si_leggono(): void
    {
        $this->getJson('/api/v1/vta/intervalli')
            ->assertOk()
            ->assertJsonPath('data.A', 60)
            ->assertJsonPath('data.B', 36)
            ->assertJsonPath('data.C', 24)
            ->assertJsonPath('defaults.C/D', 12)
            ->assertJsonPath('personalizzato', false);
    }

    public function test_i_mesi_cambiati_guidano_le_nuove_valutazioni(): void
    {
        $this->putJson('/api/v1/vta/intervalli', [
            'mesi' => ['A' => 48, 'B' => 24, 'C' => 12, 'C/D' => 6],
        ])->assertOk()->assertJsonPath('data.B', 24)->assertJsonPath('personalizzato', true);

        $id = $this->creaAlbero();
        $valutazione = $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => '2026-08-01',
            'failure_class' => 'B',
        ])->assertCreated()->json('data');

        // 24 mesi dal sopralluogo, non i 36 predefiniti
        $this->assertSame('2028-08-01', substr($valutazione['next_check_due'], 0, 10));
    }

    public function test_una_data_scritta_a_mano_non_viene_toccata(): void
    {
        $this->putJson('/api/v1/vta/intervalli', [
            'mesi' => ['A' => 48, 'B' => 24, 'C' => 12, 'C/D' => 6],
        ])->assertOk();

        $id = $this->creaAlbero();
        $valutazione = $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => '2026-08-01',
            'failure_class' => 'B',
            'next_check_due' => '2027-03-15',
        ])->assertCreated()->json('data');

        $this->assertSame('2027-03-15', substr($valutazione['next_check_due'], 0, 10));
    }

    public function test_il_ripristino_torna_ai_predefiniti(): void
    {
        $this->putJson('/api/v1/vta/intervalli', [
            'mesi' => ['A' => 10, 'B' => 10, 'C' => 10, 'C/D' => 10],
        ])->assertOk()->assertJsonPath('personalizzato', true);

        $this->putJson('/api/v1/vta/intervalli', ['ripristina' => true])
            ->assertOk()
            ->assertJsonPath('data.A', 60)
            ->assertJsonPath('personalizzato', false);
    }

    public function test_i_mesi_strampalati_vengono_rifiutati(): void
    {
        // Zero mesi non è un intervallo; e senza niente non c'è cosa salvare
        $this->putJson('/api/v1/vta/intervalli', [
            'mesi' => ['A' => 0, 'B' => 36, 'C' => 24, 'C/D' => 12],
        ])->assertStatus(422)->assertJsonValidationErrors(['mesi.A']);

        $this->putJson('/api/v1/vta/intervalli', [])->assertStatus(422);

        $this->putJson('/api/v1/vta/intervalli', [
            'mesi' => ['A' => 60, 'B' => 36, 'C' => 24],
        ])->assertStatus(422)->assertJsonValidationErrors(['mesi.C/D']);

        // "ripristina: false" senza mesi non è né un ripristino né un
        // salvataggio: rifiuto pulito, non un errore del server
        $this->putJson('/api/v1/vta/intervalli', ['ripristina' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['mesi']);
    }

    public function test_serve_il_permesso_di_gestione_utenti(): void
    {
        $senzaRuoli = \App\Models\User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        $this->actingAsTenantUser($senzaRuoli);

        $this->getJson('/api/v1/vta/intervalli')->assertForbidden();
        $this->putJson('/api/v1/vta/intervalli', [
            'mesi' => ['A' => 1, 'B' => 1, 'C' => 1, 'C/D' => 1],
        ])->assertForbidden();
    }

    public function test_il_salvataggio_non_cancella_le_altre_impostazioni(): void
    {
        // Nella stessa colonna "settings" vivono anche i dati del
        // professionista: il salvataggio degli intervalli non deve toccarli
        $this->putJson('/api/v1/perizia/settings', ['luogo' => 'Roccacannuccia di Sopra'])->assertOk();

        $this->putJson('/api/v1/vta/intervalli', [
            'mesi' => ['A' => 48, 'B' => 24, 'C' => 12, 'C/D' => 6],
        ])->assertOk();

        $this->getJson('/api/v1/perizia/settings')
            ->assertOk()
            ->assertJsonPath('data.luogo', 'Roccacannuccia di Sopra');

        $this->getJson('/api/v1/vta/intervalli')->assertOk()->assertJsonPath('data.A', 48);
    }
}
