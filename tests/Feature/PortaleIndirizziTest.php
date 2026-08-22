<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Indirizzo pubblico di ogni Comune: elenco per chi gestisce il server e
 * generazione della configurazione del server web dal solo file .env.
 */
class PortaleIndirizziTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Client $client;

    private $utente;

    protected function setUp(): void
    {
        parent::setUp();

        [$organizzazione, $utente] = $this->createTenantUser();
        $this->createArea($organizzazione);
        $this->client = Client::withoutGlobalScopes()->where('tenant_id', $organizzazione->id)->firstOrFail();
        $this->client->forceFill(['public_slug' => 'mentana', 'public_enabled' => true])->save();
        $this->utente = $utente;
    }

    public function test_senza_dominio_l_elenco_mostra_l_indirizzo_di_collaudo_e_spiega_cosa_manca(): void
    {
        config(['portal.base_host' => null]);

        $this->artisan('portale:indirizzi')
            ->expectsOutputToContain('set-portal-domain.sh')
            ->expectsOutputToContain('/comune/mentana')
            ->assertExitCode(0);
    }

    public function test_con_il_dominio_l_elenco_mostra_il_sottodominio_del_comune(): void
    {
        config(['portal.base_host' => 'censimentoalberi.it']);

        $this->artisan('portale:indirizzi')
            ->expectsOutputToContain('https://mentana.censimentoalberi.it')
            ->assertExitCode(0);
    }

    public function test_l_elenco_distingue_i_portali_accesi_da_quelli_spenti(): void
    {
        config(['portal.base_host' => 'censimentoalberi.it']);

        $this->artisan('portale:indirizzi')->expectsOutputToContain('acceso')->assertExitCode(0);

        $this->client->forceFill(['public_enabled' => false])->save();

        $this->artisan('portale:indirizzi')->expectsOutputToContain('spento')->assertExitCode(0);
    }

    public function test_solo_nomi_stampa_gli_indirizzi_dei_soli_portali_accesi(): void
    {
        config(['portal.base_host' => 'censimentoalberi.it']);

        $secondo = Client::withoutGlobalScopes()->create([
            'tenant_id' => $this->client->tenant_id,
            'name' => 'Comune di Guidonia',
            'client_type' => 'public',
            'is_active' => true,
            'public_slug' => 'guidonia',
            'public_enabled' => false,
        ]);

        $this->artisan('portale:indirizzi --solo-nomi')
            ->expectsOutput('mentana.censimentoalberi.it')
            ->doesntExpectOutput('guidonia.censimentoalberi.it')
            ->assertExitCode(0);

        $secondo->forceFill(['public_enabled' => true])->save();

        $this->artisan('portale:indirizzi --solo-nomi')
            ->expectsOutput('guidonia.censimentoalberi.it')
            ->assertExitCode(0);
    }

    public function test_il_committente_di_un_ente_disattivato_non_compare(): void
    {
        config(['portal.base_host' => 'censimentoalberi.it']);

        Organization::withoutGlobalScopes()->whereKey($this->client->tenant_id)
            ->update(['is_active' => false]);

        $this->artisan('portale:indirizzi')
            ->expectsOutputToContain('Nessun committente')
            ->assertExitCode(0);
    }

    public function test_la_pagina_territorio_riceve_il_dominio_dei_portali(): void
    {
        config(['portal.base_host' => 'censimentoalberi.it']);
        $this->actingAsTenantUser($this->utente);

        $this->get('/territorio')
            ->assertOk()
            ->assertSee('censimentoalberi.it', false);
    }

    public function test_la_configurazione_del_server_web_nasce_dal_file_env(): void
    {
        $cartella = sys_get_temp_dir().'/webgis-caddy-'.uniqid();
        mkdir($cartella);
        file_put_contents($cartella.'/.env', "APP_URL=https://gestionale.esempio.it\nPORTAL_BASE_HOST=censimentoalberi.it\n");

        $generata = $this->generaCaddyfile($cartella);

        // Il blocco dei portali porta il dominio vero, non un esempio
        $this->assertStringContainsString('*.censimentoalberi.it {', $generata);
        $this->assertStringContainsString('gestionale.esempio.it {', $generata);
        $this->assertStringContainsString('on_demand', $generata);
        // Il certificato al volo passa sempre dal controllo dell'applicazione
        $this->assertStringContainsString('ask http://127.0.0.1:8081/interno/tls', $generata);
        $this->assertStringNotContainsString('SOSTITUIRE', $generata);
        // Opzioni tolte da Caddy 2.8: se tornassero, il server web
        // rifiuterebbe l'intera configurazione al primo riavvio
        $this->assertStringNotContainsString('interval ', $generata);
        $this->assertStringNotContainsString('burst ', $generata);
    }

    public function test_senza_dominio_dei_portali_non_si_chiedono_certificati_al_volo(): void
    {
        $cartella = sys_get_temp_dir().'/webgis-caddy-'.uniqid();
        mkdir($cartella);
        file_put_contents($cartella.'/.env', "APP_URL=http://80.211.79.223\n");

        $generata = $this->generaCaddyfile($cartella);

        // Con un indirizzo IP il blocco deve essere in http: chiedere un
        // certificato per un indirizzo IP fallirebbe e il sito resterebbe giù
        $this->assertStringContainsString('http://80.211.79.223 {', $generata);
        $this->assertStringNotContainsString('on_demand', $generata);
        $this->assertStringNotContainsString('*.', $generata);
    }

    public function test_passando_a_un_nome_il_vecchio_indirizzo_numerico_rimanda_al_nome(): void
    {
        $cartella = sys_get_temp_dir().'/webgis-caddy-'.uniqid();
        mkdir($cartella);
        file_put_contents($cartella.'/.env', "APP_URL=https://gestionale.esempio.it\n");

        $generata = $this->generaCaddyfile($cartella, '80.211.79.223');

        // I cartellini con il QR stampati quando il sito era all'indirizzo
        // numerico devono continuare a funzionare
        $this->assertStringContainsString('http://80.211.79.223 {', $generata);
        $this->assertStringContainsString('redir https://gestionale.esempio.it{uri} permanent', $generata);
    }

    private function generaCaddyfile(string $cartella, string $ip = '80.211.79.223'): string
    {
        $script = base_path('deploy/caddy-config.sh');
        $uscita = $cartella.'/Caddyfile';

        $comando = sprintf(
            'WEBGIS_PROVA=1 WEBGIS_IP_SERVER=%s WEBGIS_APP_DIR=%s WEBGIS_CADDYFILE=%s bash %s 2>&1',
            escapeshellarg($ip), escapeshellarg($cartella), escapeshellarg($uscita), escapeshellarg($script),
        );

        exec($comando, $righe, $esito);
        $this->assertSame(0, $esito, "Lo script non è andato a buon fine:\n".implode("\n", $righe));

        return (string) file_get_contents($uscita);
    }
}
