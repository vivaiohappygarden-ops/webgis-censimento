<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HomeRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Dove atterra ognuno dopo l'accesso.
 *
 * Chi lavora in campo deve trovarsi davanti la schermata operativa dell'app
 * di campo, non la mappa del gestionale: e' li' che passa la giornata, e un
 * tocco in meno con i guanti conta. Chi programma i lavori o gestisce gli
 * utenti atterra invece nel programma completo.
 *
 * La regola guarda che cosa uno puo' fare, non come si chiama il suo ruolo:
 * con i ruoli su misura i nomi non sono piu' cinque.
 */
class SchermataOperativaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private function utenteCon(string $ruolo): User
    {
        [$organizzazione, $utente] = $this->createTenantUser([], $ruolo);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organizzazione->id);

        return $utente;
    }

    public function test_l_operatore_atterra_sull_app_di_campo(): void
    {
        $this->assertSame('operatore', HomeRoute::for($this->utenteCon('operatore')));
    }

    public function test_chi_programma_i_lavori_atterra_nel_gestionale(): void
    {
        // Nella veste nuova (di serie dal 26/09/2026) la casa dell'ufficio e'
        // Oggi; chi ha scelto la veste precedente atterra sulla mappa
        $this->assertSame('oggi', HomeRoute::for($this->utenteCon('tecnico')));
        $this->assertSame('oggi', HomeRoute::for($this->utenteCon('amministratore')));

        $tecnico = $this->utenteCon('tecnico');
        $tecnico->settings = ['interfaccia' => 'precedente'];
        $tecnico->save();
        $this->assertSame('mappa', HomeRoute::for($tecnico->fresh()));
    }

    public function test_i_portali_restano_dove_erano(): void
    {
        $this->assertSame('portale', HomeRoute::for($this->utenteCon('cliente')));
        $this->assertSame('impresa', HomeRoute::for($this->utenteCon('impresa')));
    }

    public function test_dopo_l_accesso_l_operatore_finisce_davvero_sull_app_di_campo(): void
    {
        $utente = $this->utenteCon('operatore');
        $utente->forceFill(['password' => 'password-di-prova'])->save();

        $this->post('/login', ['email' => $utente->email, 'password' => 'password-di-prova'])
            ->assertRedirect(route('operatore'));
    }

    public function test_l_app_di_campo_si_apre_e_resta_riservata(): void
    {
        // L'operatore la apre...
        $this->actingAsTenantUser($this->utenteCon('operatore'));
        $this->get('/operatore')->assertOk();

        // ...il cliente del portale no: non censisce
        $this->actingAsTenantUser($this->utenteCon('cliente'));
        $this->get('/operatore')->assertForbidden();
    }

    public function test_un_ruolo_su_misura_di_campo_atterra_sull_app_di_campo(): void
    {
        [$organizzazione, $amministratore] = $this->createTenantUser();
        $this->actingAsTenantUser($amministratore);

        $this->postJson('/api/v1/roles', [
            'nome' => 'Giardiniere',
            'permessi' => ['assets.view', 'assets.create', 'assets.update', 'works.view'],
        ])->assertCreated();

        $giardiniere = User::factory()->create(['tenant_id' => $organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organizzazione->id);
        $giardiniere->assignRole('Giardiniere');

        $this->assertSame('operatore', HomeRoute::for($giardiniere->fresh()));
    }

    public function test_un_ruolo_su_misura_che_programma_i_lavori_resta_nel_gestionale(): void
    {
        [$organizzazione, $amministratore] = $this->createTenantUser();
        $this->actingAsTenantUser($amministratore);

        $this->postJson('/api/v1/roles', [
            'nome' => 'Capo squadra',
            'permessi' => ['assets.view', 'assets.create', 'works.view', 'works.manage'],
        ])->assertCreated();

        $capo = User::factory()->create(['tenant_id' => $organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organizzazione->id);
        $capo->assignRole('Capo squadra');

        $this->assertSame('oggi', HomeRoute::for($capo->fresh()));
    }
}
