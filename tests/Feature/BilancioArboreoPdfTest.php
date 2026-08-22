<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Bilancio arboreo per singolo committente e documento da consegnare.
 *
 * Il bilancio di fine mandato riguarda un Comune: sommare piu' enti in un
 * foglio solo darebbe un numero che l'ente non puo' usare.
 */
class BilancioArboreoPdfTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organization;

    private $utente;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organization, $this->utente] = $this->createTenantUser();
        $this->createArea($this->organization);
        $this->actingAsTenantUser($this->utente);
    }

    private function client(): Client
    {
        return Client::withoutGlobalScopes()->where('tenant_id', $this->organization->id)->firstOrFail();
    }

    public function test_il_documento_si_scarica_ed_e_un_pdf(): void
    {
        $risposta = $this->get('/api/v1/vta/bilancio/pdf?from=2026-01-01&to=2026-12-31');

        $risposta->assertOk();
        $risposta->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $risposta->getContent());
    }

    public function test_il_nome_del_file_porta_il_periodo(): void
    {
        $this->get('/api/v1/vta/bilancio/pdf?from=2026-01-01&to=2026-06-30')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="bilancio-arboreo-2026-01-01_2026-06-30.pdf"');
    }

    public function test_il_bilancio_si_puo_restringere_a_un_committente(): void
    {
        $client = $this->client();

        $this->getJson("/api/v1/vta/bilancio?from=2026-01-01&to=2026-12-31&client_id={$client->id}")
            ->assertOk()
            ->assertJsonPath('data.client', $client->name);

        // Senza committente il bilancio e' quello complessivo dell'impresa
        $this->getJson('/api/v1/vta/bilancio?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('data.client', null);
    }

    public function test_il_committente_di_un_altra_impresa_non_e_raggiungibile(): void
    {
        [$altra] = $this->createTenantUser();
        $this->createArea($altra);
        $estraneo = Client::withoutGlobalScopes()->where('tenant_id', $altra->id)->firstOrFail();

        $this->getJson("/api/v1/vta/bilancio?from=2026-01-01&to=2026-12-31&client_id={$estraneo->id}")
            ->assertNotFound();

        $this->get("/api/v1/vta/bilancio/pdf?from=2026-01-01&to=2026-12-31&client_id={$estraneo->id}")
            ->assertNotFound();
    }

    public function test_le_date_sbagliate_vengono_rifiutate(): void
    {
        $this->getJson('/api/v1/vta/bilancio/pdf?from=2026-12-31&to=2026-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_senza_il_permesso_di_vedere_il_censimento_non_si_scarica(): void
    {
        [$org, $senzaPermessi] = $this->createTenantUser(role: 'cliente');
        $this->actingAsTenantUser($senzaPermessi);

        $this->get('/api/v1/vta/bilancio/pdf?from=2026-01-01&to=2026-12-31')->assertForbidden();
    }
}
