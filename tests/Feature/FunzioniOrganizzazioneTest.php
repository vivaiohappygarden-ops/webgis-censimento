<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Sicurezza\DueFattori;
use App\Support\Funzioni;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Funzioni accese per organizzazione dalla console della piattaforma: il
 * collegamento al gestionale giardini nasce spento, le sue chiamate rispondono
 * 403 e le pagine non lo mostrano; acceso dalla console, torna tutto.
 */
class FunzioniOrganizzazioneTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    }

    public function test_il_gestionale_giardini_nasce_spento_e_le_sue_chiamate_sono_chiuse(): void
    {
        [$organizzazione, $utente] = $this->createTenantUser();
        $this->actingAsTenantUser($utente);
        $this->assertFalse(Funzioni::attiva($organizzazione->id, Funzioni::GESTIONALE_GIARDINI));
        $this->getJson('/api/v1/gestionale/settings')->assertForbidden();
        $this->getJson('/api/v1/gestionale/dispatches')->assertForbidden();
        $this->get('/utenti')->assertOk()->assertInertia(fn ($pagina) => $pagina->where('funzioni.gestionale_giardini', false));
    }

    public function test_la_console_accende_la_funzione_e_solo_il_gestore_puo_farlo(): void
    {
        [$organizzazione, $utente] = $this->createTenantUser();
        [, $gestore] = $this->createTenantUser();
        $gestore->forceFill(['is_platform_manager' => true])->save();
        $avvio = DueFattori::avvia($gestore);
        DueFattori::conferma($gestore, Totp::codice($avvio['segreto'], Totp::passo()));

        // Un amministratore qualunque non tocca le funzioni della propria o di altre organizzazioni
        $this->actingAsTenantUser($utente);
        $this->putJson("/api/v1/piattaforma/organizzazioni/{$organizzazione->id}/funzioni", ['gestionale_giardini' => true])->assertForbidden();

        $this->actingAsTenantUser($gestore->refresh());
        $risposta = $this->putJson("/api/v1/piattaforma/organizzazioni/{$organizzazione->id}/funzioni", ['gestionale_giardini' => true])->assertOk();
        $this->assertTrue($risposta->json('data.gestionale_giardini'));
        $this->assertTrue(Funzioni::attiva($organizzazione->fresh(), Funzioni::GESTIONALE_GIARDINI));
        $riga = collect($this->getJson('/api/v1/piattaforma/organizzazioni')->assertOk()->json('data'))->firstWhere('id', $organizzazione->id);
        $this->assertTrue($riga['gestionale_giardini']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'piattaforma.funzioni', 'tenant_id' => $gestore->tenant_id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'piattaforma.funzioni', 'tenant_id' => $organizzazione->id]);

        // Accesa, l'organizzazione la usa e la vede
        $this->actingAsTenantUser($utente);
        $this->getJson('/api/v1/gestionale/settings')->assertOk();
        $this->get('/utenti')->assertOk()->assertInertia(fn ($pagina) => $pagina->where('funzioni.gestionale_giardini', true));

        // Spenta di nuovo: chiusa anche se l'indirizzo e' ancora configurato
        $this->actingAsTenantUser($gestore);
        $this->putJson("/api/v1/piattaforma/organizzazioni/{$organizzazione->id}/funzioni", ['gestionale_giardini' => false])->assertOk();
        $this->actingAsTenantUser($utente);
        $this->getJson('/api/v1/gestionale/settings')->assertForbidden();
    }

    public function test_chi_aveva_gia_il_gestionale_configurato_lo_tiene_acceso_dopo_l_aggiornamento(): void
    {
        [$organizzazione] = $this->createTenantUser();
        Organization::query()->whereKey($organizzazione->id)->update(['settings' => json_encode(['gestionale' => ['endpoint' => 'https://giardini.esempio.it/?rest_route=/yourgarden/v1/sopralluoghi', 'token' => 'x']])]);
        [$altra] = $this->createTenantUser();
        // La stessa regola della migrazione, rilanciata a mano
        (require database_path('migrations/2026_10_04_140000_funzioni_organizzazione.php'))->up();
        $this->assertTrue(Funzioni::attiva($organizzazione->fresh(), Funzioni::GESTIONALE_GIARDINI));
        $this->assertFalse(Funzioni::attiva($altra->fresh(), Funzioni::GESTIONALE_GIARDINI));
    }
}
