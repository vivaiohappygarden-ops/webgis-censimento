<?php

namespace Tests\Feature;

use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\TestCase;

/**
 * Rilevatori abilitati alle valutazioni di stabilita': elenco
 * dell'organizzazione regolato da chi gestisce gli utenti, scelto nella
 * scheda VTA e conservato nella valutazione, stampato nella perizia.
 */
class RilevatoriTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->actingAsTenantUser($this->utente);
    }

    private function albero(): string
    {
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');

        return $this->postJson('/api/v1/assets', ['area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry()])
            ->assertCreated()->json('data.id');
    }

    public function test_l_elenco_si_regola_da_chi_gestisce_gli_utenti_e_si_legge_da_chi_valuta(): void
    {
        $risposta = $this->putJson('/api/v1/rilevatori', ['rilevatori' => [
            ['nome' => '  Giulia Verdi ', 'titolo' => 'Dott.ssa agronoma', 'iscrizione' => 'Ordine dei Dottori Agronomi di Roma n. 1234', 'partita_iva' => '01234567890'],
            ['nome' => 'Marco Neri', 'titolo' => '', 'iscrizione' => null],
        ]])->assertOk()->json('data');
        $this->assertCount(2, $risposta);
        $this->assertSame('Giulia Verdi', $risposta[0]['nome']);
        $this->assertSame('01234567890', $risposta[0]['partita_iva']);
        $this->assertNull($risposta[1]['titolo']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $risposta[0]['id']);
        // jsonb riordina le chiavi: si confrontano i valori, non l'ordine
        $this->assertEquals($risposta, $this->getJson('/api/v1/rilevatori')->assertOk()->json('data'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'rilevatori.aggiornati']);

        // Senza nome la voce non entra
        $this->putJson('/api/v1/rilevatori', ['rilevatori' => [['titolo' => 'Agronomo']]])->assertStatus(422);
        // Togliere una voce: si rimanda l'elenco senza di lei, con lo stesso id dell'altra
        $this->putJson('/api/v1/rilevatori', ['rilevatori' => [$risposta[0]]])->assertOk();
        $this->assertSame([$risposta[0]['id']], array_column($this->getJson('/api/v1/rilevatori')->json('data'), 'id'));

        // Il tecnico della stessa organizzazione legge l'elenco (gli serve nella scheda VTA) ma non lo cambia
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $tecnico = \App\Models\User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        $tecnico->assignRole('tecnico');
        $this->actingAsTenantUser($tecnico);
        $this->getJson('/api/v1/rilevatori')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson('/api/v1/rilevatori', ['rilevatori' => []])->assertForbidden();
    }

    public function test_la_valutazione_conserva_i_dati_del_rilevatore_e_la_perizia_li_stampa(): void
    {
        $stampe = new RaccoglitorePdf;
        $this->app->instance(PdfRenderer::class, $stampe);
        $albero = $this->albero();
        $dettagli = ['id' => 'abc', 'nome' => 'Giulia Verdi', 'titolo' => 'Dott.ssa agronoma', 'iscrizione' => 'Ordine dei Dottori Agronomi di Roma n. 1234', 'partita_iva' => '01234567890'];

        $valutazione = $this->postJson("/api/v1/assets/{$albero}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => now('Europe/Rome')->toDateString(), 'failure_class' => 'B',
            'assessor_external' => 'Giulia Verdi', 'assessor_details' => $dettagli,
            'targets' => ['ALB-0021 · Panchina', 'marciapiede'],
        ])->assertCreated()->json('data');
        $this->assertSame('01234567890', $valutazione['assessor_details']['partita_iva']);
        $this->assertSame($dettagli, $this->getJson("/api/v1/assets/{$albero}/assessments")->assertOk()->json('data.0.assessor_details'));

        $this->get("/api/v1/assessments/{$valutazione['id']}/perizia-pdf")->assertOk();
        $this->assertSame('Giulia Verdi - Dott.ssa agronoma - Ordine dei Dottori Agronomi di Roma n. 1234 - P. IVA 01234567890', $stampe->dati['pdf.perizia']['rilevatore']);
        $this->assertStringContainsString('ALB-0021 · Panchina', $stampe->html['pdf.perizia']);

        // Senza elenco resta il nome scritto a mano, e un dettaglio vuoto non lascia trattini
        $seconda = $this->postJson("/api/v1/assets/{$albero}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => now('Europe/Rome')->toDateString(), 'failure_class' => 'A', 'assessor_external' => 'Mario Rossi',
        ])->assertCreated()->json('data');
        $this->get("/api/v1/assessments/{$seconda['id']}/perizia-pdf")->assertOk();
        $this->assertSame('Mario Rossi', $stampe->dati['pdf.perizia']['rilevatore']);

        // Le prescrizioni ricorrenti arrivano alla scheda dal dizionario condiviso
        $this->assertContains('Rimonda del secco nella prossima stagione di riposo vegetativo', config('agronomia.prescrizioni_vta'));
        $this->get("/censimento/{$albero}")->assertOk()->assertInertia(fn ($pagina) => $pagina->has('agronomia.prescrizioni_vta'));
    }
}
