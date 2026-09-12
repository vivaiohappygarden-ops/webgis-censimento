<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Ruoli su misura.
 *
 * I cinque di serie restano (il programma li chiama per nome), ma i loro
 * permessi si possono cambiare e se ne possono creare di nuovi. Due guardie
 * non si toccano: l'amministratore resta com'e', e i permessi dei portali
 * esterni non si mescolano con quelli del gestionale.
 */
class RuoliPersonalizzatiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->actingAsTenantUser($this->utente);
    }

    private function ruolo(string $nome): Role
    {
        return Role::query()->where('name', $nome)->firstOrFail();
    }

    public function test_l_elenco_porta_i_ruoli_con_i_permessi_e_il_catalogo(): void
    {
        $risposta = $this->getJson('/api/v1/roles')->assertOk();

        $nomi = collect($risposta->json('data'))->pluck('nome');
        foreach (['amministratore', 'tecnico', 'operatore', 'cliente', 'impresa'] as $atteso) {
            $this->assertContains($atteso, $nomi);
        }

        $amministratore = collect($risposta->json('data'))->firstWhere('nome', 'amministratore');
        $this->assertTrue($amministratore['intoccabile']);
        $this->assertSame(1, $amministratore['utenti'], "L'utente della prova e' amministratore");

        // Il catalogo dei permessi arriva con l'elenco, spiegato in italiano
        $permessi = collect($risposta->json('permessi'));
        $this->assertNotEmpty($permessi);
        $censimento = $permessi->firstWhere('chiave', 'assets.delete');
        $this->assertSame('Censimento', $censimento['gruppo']);
        $this->assertNotEmpty($censimento['spiegazione']);
    }

    public function test_si_crea_un_ruolo_su_misura_e_lo_si_assegna(): void
    {
        $this->postJson('/api/v1/roles', [
            'nome' => 'Ufficio tecnico in lettura',
            'permessi' => ['assets.view', 'areas.view', 'works.view'],
        ])->assertCreated();

        $creato = $this->postJson('/api/v1/users', [
            'name' => 'Mario Rossi',
            'email' => 'mario.rossi@comune.example',
            'role' => 'Ufficio tecnico in lettura',
        ])->assertCreated();

        $utente = User::query()->findOrFail($creato->json('data.id'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);

        $this->assertTrue($utente->hasRole('Ufficio tecnico in lettura'));
        $this->assertTrue($utente->can('assets.view'));
        $this->assertFalse($utente->can('assets.update'), 'In sola lettura non si modifica');
        $this->assertSame('internal', $utente->user_type);
    }

    public function test_il_ruolo_nuovo_vale_davvero_sulle_pagine(): void
    {
        $this->postJson('/api/v1/roles', [
            'nome' => 'Capo squadra',
            'permessi' => ['assets.view', 'works.view', 'works.manage'],
        ])->assertCreated();

        $capo = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $capo->assignRole('Capo squadra');
        $this->actingAsTenantUser($capo);

        // Puo' leggere il censimento e gestire i lavori...
        $this->getJson('/api/v1/assets')->assertOk();
        $this->getJson('/api/v1/work-orders')->assertOk();
        // ...ma non tocca gli utenti
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_i_permessi_di_un_ruolo_di_serie_si_possono_cambiare(): void
    {
        $tecnico = $this->ruolo('tecnico');

        $this->patchJson("/api/v1/roles/{$tecnico->id}", [
            // Da noi il tecnico non deve poter cancellare le schede
            'permessi' => ['catalog.view', 'areas.view', 'assets.view', 'assets.create',
                'assets.update', 'clients.view', 'works.view', 'works.manage'],
        ])->assertOk();

        $utente = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $utente->assignRole('tecnico');

        $this->assertTrue($utente->can('assets.update'));
        $this->assertFalse($utente->can('assets.delete'));
    }

    public function test_l_amministratore_non_si_tocca(): void
    {
        $amministratore = $this->ruolo('amministratore');

        $this->patchJson("/api/v1/roles/{$amministratore->id}", [
            'permessi' => ['assets.view'],
        ])->assertStatus(422);

        $this->deleteJson("/api/v1/roles/{$amministratore->id}")->assertStatus(422);

        // I permessi sono rimasti tutti
        $this->assertTrue($amministratore->fresh()->hasPermissionTo('users.manage'));
    }

    public function test_i_ruoli_di_serie_non_si_rinominano_ne_si_eliminano(): void
    {
        $operatore = $this->ruolo('operatore');

        $this->patchJson("/api/v1/roles/{$operatore->id}", [
            'nome' => 'Giardiniere',
            'permessi' => ['assets.view'],
        ])->assertStatus(422);

        $this->deleteJson("/api/v1/roles/{$operatore->id}")->assertStatus(422);
        $this->assertSame('operatore', $operatore->fresh()->name);
    }

    public function test_i_permessi_dei_portali_non_si_mescolano(): void
    {
        $this->postJson('/api/v1/roles', [
            'nome' => 'Mezzo cliente',
            'permessi' => ['portal.view', 'assets.view'],
        ])->assertStatus(422)->assertJsonValidationErrors('permessi');

        // Ognuno per conto suo va bene
        $this->postJson('/api/v1/roles', [
            'nome' => 'Solo portale',
            'permessi' => ['portal.view'],
        ])->assertCreated();
    }

    public function test_un_ruolo_assegnato_non_si_elimina(): void
    {
        $creato = $this->postJson('/api/v1/roles', [
            'nome' => 'Agronomo esterno',
            'permessi' => ['assets.view', 'assets.update'],
        ])->assertCreated();
        $id = $creato->json('data.id');

        $this->postJson('/api/v1/users', [
            'name' => 'Anna Verdi',
            'email' => 'anna.verdi@studio.example',
            'role' => 'Agronomo esterno',
        ])->assertCreated();

        $this->deleteJson("/api/v1/roles/{$id}")->assertStatus(422);

        // Spostato l'utente, il ruolo si elimina
        $utente = User::query()->where('email', 'anna.verdi@studio.example')->firstOrFail();
        $this->patchJson("/api/v1/users/{$utente->id}", ['role' => 'operatore'])->assertOk();
        $this->deleteJson("/api/v1/roles/{$id}")->assertOk();
        $this->assertNull(Role::query()->find($id));
    }

    public function test_un_nome_gia_in_uso_o_malfatto_viene_rifiutato(): void
    {
        $this->postJson('/api/v1/roles', ['nome' => 'tecnico', 'permessi' => ['assets.view']])
            ->assertStatus(422);
        $this->postJson('/api/v1/roles', ['nome' => 'ab', 'permessi' => ['assets.view']])
            ->assertStatus(422);
        $this->postJson('/api/v1/roles', ['nome' => '<script>', 'permessi' => ['assets.view']])
            ->assertStatus(422);
        $this->postJson('/api/v1/roles', ['nome' => 'Valido', 'permessi' => ['inventato.tutto']])
            ->assertStatus(422);
    }

    public function test_i_ruoli_di_un_altra_organizzazione_non_si_vedono_ne_si_toccano(): void
    {
        [$altra, $suoAmministratore] = $this->createTenantUser();
        app(PermissionRegistrar::class)->setPermissionsTeamId($altra->id);
        $suoRuolo = Role::query()->where('tenant_id', $altra->id)->where('name', 'tecnico')->firstOrFail();

        $this->actingAsTenantUser($this->utente);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);

        $nomi = collect($this->getJson('/api/v1/roles')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($suoRuolo->id, $nomi);

        $this->patchJson("/api/v1/roles/{$suoRuolo->id}", ['permessi' => ['assets.view']])->assertNotFound();
    }

    public function test_senza_il_permesso_sugli_utenti_i_ruoli_non_si_vedono(): void
    {
        [$organizzazione, $tecnico] = $this->createTenantUser([], 'tecnico');
        $this->actingAsTenantUser($tecnico);

        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->postJson('/api/v1/roles', ['nome' => 'Abusivo', 'permessi' => ['assets.view']])->assertForbidden();
    }
}
