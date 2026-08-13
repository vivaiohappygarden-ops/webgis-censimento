<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->admin] = $this->createTenantUser();
        $this->actingAsTenantUser($this->admin);
    }

    public function test_admin_creates_users_with_roles_and_temporary_password(): void
    {
        $body = $this->postJson('/api/v1/users', [
            'name' => 'Mario Operaio', 'email' => 'Mario@Demo.Local', 'role' => 'operatore',
        ])->assertCreated()->json();

        $this->assertSame('operatore', $body['data']['role']);
        $this->assertSame('internal', $body['data']['user_type']);
        $this->assertSame('mario@demo.local', $body['data']['email']);
        $this->assertSame(12, strlen($body['temporary_password']));

        // La password provvisoria funziona davvero
        $created = User::query()->findOrFail($body['data']['id']);
        $this->assertTrue(Hash::check($body['temporary_password'], $created->password));

        // Email duplicata rifiutata con messaggio chiaro
        $this->postJson('/api/v1/users', [
            'name' => 'Doppione', 'email' => 'mario@demo.local', 'role' => 'operatore',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        // Il ruolo cliente esige il collegamento a un cliente
        $this->postJson('/api/v1/users', [
            'name' => 'Sig. Rossi', 'email' => 'rossi@demo.local', 'role' => 'cliente',
        ])->assertUnprocessable();

        $client = Client::create(['tenant_id' => $this->organization->id, 'name' => 'Condominio', 'client_type' => 'private']);
        $portal = $this->postJson('/api/v1/users', [
            'name' => 'Sig. Rossi', 'email' => 'rossi@demo.local', 'role' => 'cliente', 'client_id' => $client->id,
        ])->assertCreated()->json('data');
        $this->assertSame('client_portal', $portal['user_type']);
        $this->assertSame('Condominio', $portal['client']['name']);

        $this->assertCount(3, $this->getJson('/api/v1/users')->assertOk()->json('data'));
    }

    public function test_guards_protect_self_and_last_administrator(): void
    {
        // Autodisattivazione vietata
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['is_active' => false])
            ->assertUnprocessable();
        // Autodeclassamento vietato
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['role' => 'operatore'])
            ->assertUnprocessable();

        // Un secondo amministratore permette entrambe le operazioni sul primo
        $other = $this->postJson('/api/v1/users', [
            'name' => 'Vice', 'email' => 'vice@demo.local', 'role' => 'amministratore',
        ])->assertCreated()->json('data');

        // Ma il vice non si può disattivare se resterebbe solo l'altro? No:
        // l'altro (io) resta attivo, quindi si può
        $this->patchJson("/api/v1/users/{$other['id']}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        // Riattivato e declassato: ammesso perché io resto amministratore
        $this->patchJson("/api/v1/users/{$other['id']}", ['is_active' => true, 'role' => 'tecnico'])
            ->assertOk()->assertJsonPath('data.role', 'tecnico');

        // Ora sono di nuovo l'unico amministratore: nessuno può togliermi
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['role' => 'tecnico'])
            ->assertUnprocessable();
    }

    public function test_deactivated_user_cannot_login_nor_keep_using_tokens(): void
    {
        $body = $this->postJson('/api/v1/users', [
            'name' => 'Tecnico Uscente', 'email' => 'uscente@demo.local', 'role' => 'tecnico',
        ])->assertCreated()->json();
        $userId = $body['data']['id'];

        // Login API funziona finché è attivo
        $this->postJson('/api/v1/auth/login', [
            'email' => 'uscente@demo.local',
            'password' => $body['temporary_password'],
            'organization' => $this->organization->slug,
        ])->assertCreated();

        // Il login di prova ha cambiato l'utente del guard: si torna admin
        $this->actingAsTenantUser($this->admin);
        $this->patchJson("/api/v1/users/{$userId}", ['is_active' => false])->assertOk();

        // Niente nuovo login
        $this->postJson('/api/v1/auth/login', [
            'email' => 'uscente@demo.local',
            'password' => $body['temporary_password'],
            'organization' => $this->organization->slug,
        ])->assertUnprocessable();

        // E una sessione già aperta viene chiusa dal middleware
        $deactivated = User::query()->withoutGlobalScopes()->findOrFail($userId);
        $this->actingAsTenantUser($deactivated);
        $this->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_password_reset_returns_working_temporary_password(): void
    {
        $body = $this->postJson('/api/v1/users', [
            'name' => 'Smemorato', 'email' => 'smemorato@demo.local', 'role' => 'operatore',
        ])->assertCreated()->json();

        $reset = $this->postJson("/api/v1/users/{$body['data']['id']}/reset-password")
            ->assertOk()->json();

        $this->assertNotSame($body['temporary_password'], $reset['temporary_password']);
        $user = User::query()->findOrFail($body['data']['id']);
        $this->assertTrue(Hash::check($reset['temporary_password'], $user->password));
        $this->assertFalse(Hash::check($body['temporary_password'], $user->password));
    }

    public function test_reset_rotates_remember_token(): void
    {
        $body = $this->postJson('/api/v1/users', [
            'name' => 'Ricordato', 'email' => 'ricordato@demo.local', 'role' => 'operatore',
        ])->assertCreated()->json();

        $user = User::query()->findOrFail($body['data']['id']);
        $user->forceFill(['remember_token' => 'vecchio-ricordami'])->save();

        $this->postJson("/api/v1/users/{$user->id}/reset-password")->assertOk();

        // Il cookie "ricordami" emesso prima del reset non deve più valere
        $this->assertNotSame('vecchio-ricordami', $user->refresh()->remember_token);
    }

    public function test_portal_user_with_deleted_client_stays_manageable(): void
    {
        $client = Client::create(['tenant_id' => $this->organization->id, 'name' => 'Effimero', 'client_type' => 'private']);
        $portal = $this->postJson('/api/v1/users', [
            'name' => 'Orfano', 'email' => 'orfano@demo.local', 'role' => 'cliente', 'client_id' => $client->id,
        ])->assertCreated()->json('data');

        // Un cliente con utenti del portale collegati non si elimina
        $this->deleteJson("/api/v1/clients/{$client->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('client');

        // Se però risulta eliminato (dati storici), l'utente resta gestibile
        $client->delete();
        $this->patchJson("/api/v1/users/{$portal['id']}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        // E il collegamento storico resta leggibile in elenco
        $row = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))
            ->firstWhere('email', 'orfano@demo.local');
        $this->assertSame('Effimero', $row['client']['name']);
    }

    public function test_deactivated_session_still_sees_public_tree_page(): void
    {
        // Elemento con pagina pubblica attiva
        $area = $this->createArea($this->organization, ['name' => 'Parco QR']);
        $type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'ALB-QR-9',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
        $token = $this->postJson("/api/v1/assets/{$assetId}/public-page")
            ->assertOk()->json('data.public_token');

        // Un utente disattivato con la sessione ancora in tasca inquadra il
        // QR: la pagina pubblica deve aprirsi, non rimbalzare al login
        $body = $this->postJson('/api/v1/users', [
            'name' => 'Ex Operaio', 'email' => 'ex@demo.local', 'role' => 'operatore',
        ])->assertCreated()->json();
        $this->patchJson("/api/v1/users/{$body['data']['id']}", ['is_active' => false])->assertOk();

        $deactivated = User::query()->withoutGlobalScopes()->findOrFail($body['data']['id']);
        $this->actingAs($deactivated)->get("/p/{$token}")->assertOk();
    }

    public function test_only_administrators_manage_users_and_tenants_are_isolated(): void
    {
        [, $tech] = [null, User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $tech->assignRole('tecnico');
        $this->actingAsTenantUser($tech);

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', [
            'name' => 'X', 'email' => 'x@demo.local', 'role' => 'operatore',
        ])->assertForbidden();

        // Un amministratore di un altro tenant non vede né tocca questi utenti
        [, $foreignAdmin] = $this->createTenantUser();
        $this->actingAsTenantUser($foreignAdmin);
        $emails = array_column($this->getJson('/api/v1/users')->assertOk()->json('data'), 'email');
        $this->assertNotContains($this->admin->email, $emails);
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['is_active' => false])->assertNotFound();
        $this->postJson("/api/v1/users/{$this->admin->id}/reset-password")->assertNotFound();
    }
}
