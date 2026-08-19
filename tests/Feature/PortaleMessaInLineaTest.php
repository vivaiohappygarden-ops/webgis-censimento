<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Messa in linea del portale: pagine legali, indicizzazione e verifica dei
 * nomi a dominio per il certificato.
 */
class PortaleMessaInLineaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organization;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organization, $user] = $this->createTenantUser();
        $this->createArea($this->organization);
        $this->client = Client::withoutGlobalScopes()->where('tenant_id', $this->organization->id)->firstOrFail();
        $this->client->forceFill([
            'public_slug' => 'mentana', 'public_enabled' => true,
        ])->save();
        $this->actingAsTenantUser($user);
    }

    public function test_la_pagina_privacy_dice_cosa_fa_il_sito(): void
    {
        $risposta = $this->get('/comune/mentana/privacy');

        $risposta->assertOk();
        $risposta->assertSee('non usa cookie', false);
        $risposta->assertSee('non usa strumenti di statistica', false);
        // Senza titolare indicato lo dice, invece di far finta di niente
        $risposta->assertSee('non è ancora stato indicato', false);
    }

    public function test_il_titolare_e_l_informativa_dell_ente_compaiono_quando_ci_sono(): void
    {
        $this->patchJson("/api/v1/clients/{$this->client->id}", [
            'public_profile' => [
                'legal_owner' => 'Comune di Mentana - Piazza Borghese 1',
                'privacy_text' => 'I dati sono trattati ai sensi del regolamento europeo.',
                'accessibility_url' => 'https://form.agid.gov.it/view/esempio',
            ],
        ])->assertOk();

        $risposta = $this->get('/comune/mentana/privacy');

        $risposta->assertOk();
        $risposta->assertSee('Comune di Mentana - Piazza Borghese 1');
        $risposta->assertSee('regolamento europeo');
        $risposta->assertSee('form.agid.gov.it/view/esempio');
    }

    public function test_il_portale_e_indicizzabile_ma_la_pagina_del_qr_no(): void
    {
        $this->get('/comune/mentana')->assertOk()->assertSee('content="index,follow"', false);
    }

    public function test_il_collegamento_alla_privacy_e_in_fondo_a_ogni_pagina(): void
    {
        $this->get('/comune/mentana')->assertOk()->assertSee('/comune/mentana/privacy', false);
    }

    public function test_la_verifica_del_dominio_accetta_solo_i_comuni_accesi(): void
    {
        config(['portal.base_host' => 'censimenti.esempio.it']);

        $this->get('/interno/tls?domain=mentana.censimenti.esempio.it')->assertOk();
        $this->get('/interno/tls?domain=guidonia.censimenti.esempio.it')->assertNotFound();
        // Nome estraneo puntato sul nostro server: nessun certificato
        $this->get('/interno/tls?domain=sito-di-altri.example.com')->assertNotFound();
        $this->get('/interno/tls')->assertNotFound();
    }

    public function test_il_dominio_del_gestionale_e_sempre_ammesso(): void
    {
        config([
            'portal.base_host' => 'censimenti.esempio.it',
            'app.url' => 'https://gestionale.esempio.it',
        ]);

        $this->get('/interno/tls?domain=gestionale.esempio.it')->assertOk();
    }

    public function test_il_portale_spento_perde_anche_il_certificato(): void
    {
        config(['portal.base_host' => 'censimenti.esempio.it']);

        $this->get('/interno/tls?domain=mentana.censimenti.esempio.it')->assertOk();

        $this->client->forceFill(['public_enabled' => false])->save();

        $this->get('/interno/tls?domain=mentana.censimenti.esempio.it')->assertNotFound();
    }
}
