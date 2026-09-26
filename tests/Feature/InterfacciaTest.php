<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HomeRoute;
use App\Support\Interfaccia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * La veste del gestionale si sceglie per utente (dal 26/09/2026): di serie
 * la nuova (bozza A, sei voci per compiti), con la possibilita' di tornare
 * alla precedente senza perdere niente. La scelta vive sull'utente, non nel
 * browser: vale su qualunque dispositivo.
 */
class InterfacciaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private User $utente;

    protected function setUp(): void
    {
        parent::setUp();
        // Le pagine stanno in js/Pages (maiuscola): cosi' assertInertia
        // controlla davvero che il file del componente esista
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
        [, $this->utente] = $this->createTenantUser();
        $this->actingAsTenantUser($this->utente);
    }

    public function test_di_serie_si_apre_la_nuova_interfaccia(): void
    {
        $this->assertSame('nuova', Interfaccia::per($this->utente));
        $this->assertSame('oggi', HomeRoute::for($this->utente));

        $this->get('/')->assertRedirect(route('oggi'));
        $this->get('/oggi')->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Nuovo/Oggi')
            ->where('interfaccia.modo', 'nuova')
            ->where('interfaccia.predefinita', 'nuova'));
    }

    public function test_si_puo_tornare_alla_precedente_e_la_scelta_resta_sull_utente(): void
    {
        $this->post('/interfaccia', ['modo' => 'precedente'])->assertRedirect('/');

        $this->assertSame('precedente', $this->utente->fresh()->settings['interfaccia']);
        $this->assertSame('mappa', HomeRoute::for($this->utente->fresh()));
        $this->get('/')->assertRedirect(route('mappa'));
        $this->get('/oggi')->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Oggi')
            ->where('interfaccia.modo', 'precedente'));

        // E si torna indietro con lo stesso gesto
        $this->post('/interfaccia', ['modo' => 'nuova'])->assertRedirect('/');
        $this->assertSame('nuova', $this->utente->fresh()->settings['interfaccia']);
        $this->get('/oggi')->assertInertia(fn (Assert $pagina) => $pagina->component('Nuovo/Oggi'));
    }

    public function test_un_modo_sconosciuto_viene_rifiutato(): void
    {
        $this->from('/oggi')->post('/interfaccia', ['modo' => 'vecchia'])
            ->assertRedirect('/oggi')->assertSessionHasErrors('modo');

        $this->assertNull($this->utente->fresh()->settings['interfaccia'] ?? null);
    }

    public function test_la_predefinita_si_decide_dalla_configurazione_e_la_scelta_personale_vince(): void
    {
        config(['interfaccia.predefinita' => 'precedente']);

        $this->assertSame('precedente', Interfaccia::per($this->utente));
        $this->assertSame('mappa', HomeRoute::for($this->utente));

        $this->utente->settings = ['interfaccia' => 'nuova'];
        $this->utente->save();
        $this->assertSame('nuova', Interfaccia::per($this->utente->fresh()));
        $this->assertSame('oggi', HomeRoute::for($this->utente->fresh()));

        // Un valore sconosciuto nella configurazione non rompe niente: si
        // ricade sulla nuova
        config(['interfaccia.predefinita' => 'boh']);
        $this->assertSame('nuova', Interfaccia::predefinita());
    }

    public function test_nella_nuova_veste_oggi_si_apre_anche_a_chi_vede_solo_il_censimento(): void
    {
        [$organizzazione, $amministratore] = $this->createTenantUser();
        $this->actingAsTenantUser($amministratore);
        $this->postJson('/api/v1/roles', ['nome' => 'Solo censimento', 'permessi' => ['assets.view']])->assertCreated();

        $lettore = User::factory()->create(['tenant_id' => $organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organizzazione->id);
        $lettore->assignRole('Solo censimento');
        $lettore = $lettore->fresh();

        $this->actingAsTenantUser($lettore);
        $this->assertSame('oggi', HomeRoute::for($lettore));
        $this->get('/oggi')->assertOk()->assertInertia(fn (Assert $pagina) => $pagina->component('Nuovo/Oggi'));

        // Nella veste precedente Oggi era il cruscotto dei lavori: senza
        // works.view resta chiuso e si atterra sulla mappa
        $lettore->settings = ['interfaccia' => 'precedente'];
        $lettore->save();
        $this->assertSame('mappa', HomeRoute::for($lettore->fresh()));
        $this->get('/oggi')->assertForbidden();
    }

    public function test_chi_non_vede_ne_censimento_ne_lavori_non_apre_oggi(): void
    {
        [, $cliente] = $this->createTenantUser([], 'cliente');
        $this->actingAsTenantUser($cliente);

        $this->get('/oggi')->assertForbidden();
        $this->getJson('/api/v1/oggi')->assertForbidden();
    }
}
