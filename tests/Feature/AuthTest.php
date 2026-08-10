<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_login_returns_token_and_me_works(): void
    {
        [$organization, $user] = $this->createTenantUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'organization']]);

        $token = $response->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.organization.slug', $organization->slug);
    }

    public function test_login_with_wrong_password_fails(): void
    {
        [, $user] = $this->createTenantUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'sbagliata',
        ])->assertUnprocessable();
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/assets')->assertUnauthorized();
        $this->getJson('/api/v1/catalog')->assertUnauthorized();
    }
}
