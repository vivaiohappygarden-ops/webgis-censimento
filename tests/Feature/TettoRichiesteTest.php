<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * I riquadri della mappa hanno un conto di richieste separato dal resto.
 *
 * Prima condividevano lo stesso tetto: spostarsi sulla mappa consumava il
 * credito delle altre pagine e poco dopo Territorio o Censimento tornavano
 * vuoti, senza dire perché.
 */
class TettoRichiesteTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_le_pagine_normali_hanno_il_tetto_generale(): void
    {
        [, $user] = $this->createTenantUser();
        $this->actingAsTenantUser($user);

        $risposta = $this->getJson('/api/v1/clients');

        $risposta->assertOk();
        $this->assertSame('600', $risposta->headers->get('X-RateLimit-Limit'));
    }

    public function test_i_riquadri_della_mappa_hanno_un_tetto_piu_alto(): void
    {
        [, $user] = $this->createTenantUser();
        $this->actingAsTenantUser($user);

        $risposta = $this->get('/api/v1/tiles/assets/15/0/0');

        $risposta->assertNoContent();
        $this->assertSame('1200', $risposta->headers->get('X-RateLimit-Limit'));
    }

    public function test_i_riquadri_non_consumano_il_credito_delle_altre_pagine(): void
    {
        [, $user] = $this->createTenantUser();
        $this->actingAsTenantUser($user);

        $prima = (int) $this->getJson('/api/v1/clients')->headers->get('X-RateLimit-Remaining');

        for ($i = 0; $i < 5; $i++) {
            $this->get("/api/v1/tiles/assets/15/{$i}/0")->assertNoContent();
        }

        $dopo = (int) $this->getJson('/api/v1/clients')->headers->get('X-RateLimit-Remaining');

        // Solo la seconda chiamata a /clients ha consumato credito: i cinque
        // riquadri in mezzo contano su un altro conto
        $this->assertSame($prima - 1, $dopo);
    }
}
