<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Support\PortalLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Convivenza fra il gestionale e i portali sullo stesso dominio.
 *
 * Con il gestionale su gestionale.dominio.it e i Comuni su
 * <comune>.dominio.it, il nome del gestionale combacia con {comune}: senza
 * un'esclusione le rotte pubbliche gli passerebbero davanti e l'intero
 * programma di gestione sparirebbe dietro un "non trovato".
 */
class PortaleSottodominioTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private const BASE = 'censimentoalberature.it';

    private Organization $organization;

    private $utente;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'portal.base_host' => self::BASE,
            'app.url' => 'https://gestionale.'.self::BASE,
        ]);

        // Le rotte per sottodominio si registrano solo se il dominio dei
        // portali e' configurato, cosa che in prova non e' vera all'avvio
        Route::middleware('portale')->group(base_path('routes/portale.php'));
        $this->app['router']->getRoutes()->refreshNameLookups();

        [$this->organization, $this->utente] = $this->createTenantUser();
        $this->createArea($this->organization);
    }

    private function accendiPortale(string $slug): Client
    {
        $client = Client::withoutGlobalScopes()->where('tenant_id', $this->organization->id)->firstOrFail();
        $client->forceFill(['public_slug' => $slug, 'public_enabled' => true])->save();

        return $client;
    }

    public function test_il_nome_del_gestionale_non_viene_scambiato_per_un_comune(): void
    {
        $this->accendiPortale('mentana');

        // Senza l'esclusione qui arriverebbe un 404: nessun committente si
        // chiama "gestionale"
        $this->get('https://gestionale.'.self::BASE.'/')
            ->assertRedirectContains('/login');
    }

    public function test_le_pagine_interne_restano_del_gestionale(): void
    {
        $this->accendiPortale('mentana');

        // /mappa esiste sia nel gestionale sia nei portali: sul nome del
        // gestionale deve vincere quella interna, che chiede l'accesso
        $this->get('https://gestionale.'.self::BASE.'/mappa')
            ->assertRedirectContains('/login');

        $this->get('https://gestionale.'.self::BASE.'/login')->assertOk();
    }

    public function test_il_sottodominio_di_un_comune_apre_il_portale(): void
    {
        $client = $this->accendiPortale('mentana');

        $this->get('https://mentana.'.self::BASE.'/')
            ->assertOk()
            ->assertSee($client->publicName(), false);
    }

    public function test_un_comune_che_comincia_come_il_gestionale_non_e_escluso(): void
    {
        $client = $this->accendiPortale('gestionaledoc');

        $this->get('https://gestionaledoc.'.self::BASE.'/')
            ->assertOk()
            ->assertSee($client->publicName(), false);
    }

    public function test_il_nome_del_gestionale_e_fra_quelli_riservati(): void
    {
        $this->assertContains('gestionale', PortalLabels::reservedSlugs());

        // E su un dominio diverso da quello dei portali non riserva niente
        config(['app.url' => 'https://gestionale.altrodominio.it']);
        $this->assertNotContains('gestionale', PortalLabels::reservedSlugs());
    }

    public function test_un_committente_non_puo_prendersi_il_nome_del_gestionale(): void
    {
        $client = Client::withoutGlobalScopes()->where('tenant_id', $this->organization->id)->firstOrFail();
        $this->actingAsTenantUser($this->utente);

        $this->patchJson("/api/v1/clients/{$client->id}", ['public_slug' => 'gestionale'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('public_slug');
    }

    public function test_senza_dominio_dei_portali_il_vincolo_non_esclude_niente(): void
    {
        $this->assertStringNotContainsString('(?!', PortalLabels::vincoloSottodominio(''));
    }
}
