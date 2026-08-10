<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class WebAuthTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_user_can_login_and_reach_the_map(): void
    {
        [, $user] = $this->createTenantUser();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('mappa'));

        $this->assertAuthenticatedAs($user);

        // forgetGuards forza la ri-risoluzione dell'utente dalla sessione, come
        // in una richiesta HTTP reale: copre la regressione della ricorsione
        // infinita tra TenantScope e SessionGuard.
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->get('/mappa')->assertOk();
        $this->get('/censimento')->assertOk();
        $this->get('/catalogo')->assertOk();
    }

    public function test_wrong_credentials_are_rejected(): void
    {
        [, $user] = $this->createTenantUser();

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'errata',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/mappa')->assertRedirect('/login');
    }

    public function test_logout_ends_the_session(): void
    {
        [, $user] = $this->createTenantUser();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
