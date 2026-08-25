<?php

namespace Tests\Feature;

use App\Models\SavedFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Viste salvate: i filtri di un elenco memorizzati con un nome.
 *
 * "Tigli da spollonare" si imposta una volta e si richiama con un clic;
 * una vista puo' essere condivisa con i colleghi e una puo' aprirsi da sola.
 */
class VisteSalvateTest extends TestCase
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

    private function salva(string $nome, array $filtri = ['q' => 'tigli'], array $extra = []): array
    {
        return $this->postJson('/api/v1/viste', [
            'pagina' => 'censimento', 'nome' => $nome, 'filtri' => $filtri, ...$extra,
        ])->assertCreated()->json('data');
    }

    public function test_una_vista_si_salva_e_si_ritrova(): void
    {
        $this->salva('Tigli da spollonare', ['q' => 'tiglio', 'status' => 'active']);

        $viste = $this->getJson('/api/v1/viste?pagina=censimento')->assertOk()->json('data');

        $this->assertCount(1, $viste);
        $this->assertSame('Tigli da spollonare', $viste[0]['nome']);
        $this->assertSame('tiglio', $viste[0]['filtri']['q']);
        $this->assertTrue($viste[0]['mia']);
    }

    public function test_salvare_con_lo_stesso_nome_aggiorna_invece_di_duplicare(): void
    {
        $this->salva('Preferita', ['q' => 'prima']);
        $this->salva('Preferita', ['q' => 'dopo']);

        $viste = $this->getJson('/api/v1/viste?pagina=censimento')->json('data');

        $this->assertCount(1, $viste);
        $this->assertSame('dopo', $viste[0]['filtri']['q']);
    }

    public function test_la_predefinita_e_una_sola_per_pagina(): void
    {
        $prima = $this->salva('Prima');
        $seconda = $this->salva('Seconda');

        $this->patchJson("/api/v1/viste/{$prima['id']}", ['predefinita' => true])->assertOk();
        $this->patchJson("/api/v1/viste/{$seconda['id']}", ['predefinita' => true])->assertOk();

        $viste = collect($this->getJson('/api/v1/viste?pagina=censimento')->json('data'));

        $this->assertSame(['Seconda'], $viste->where('predefinita', true)->pluck('nome')->all());
    }

    public function test_la_vista_condivisa_si_vede_ma_non_si_tocca(): void
    {
        $condivisa = $this->salva('Della squadra', ['q' => 'platani'], ['condivisa' => true]);
        $this->salva('Solo mia');

        // Un collega della stessa organizzazione
        $collega = \App\Models\User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $collega->assignRole('tecnico');
        $this->actingAsTenantUser($collega);

        $viste = collect($this->getJson('/api/v1/viste?pagina=censimento')->json('data'));

        $this->assertSame(['Della squadra'], $viste->pluck('nome')->all());
        $this->assertFalse($viste->first()['mia']);

        // Ne' eliminarla ne' farne la propria predefinita
        $this->deleteJson("/api/v1/viste/{$condivisa['id']}")->assertNotFound();
        $this->patchJson("/api/v1/viste/{$condivisa['id']}", ['predefinita' => true])->assertNotFound();
    }

    public function test_la_predefinita_di_un_collega_non_vale_per_me(): void
    {
        // Il titolare rende predefinita la sua vista condivisa
        $condivisa = $this->salva('Della squadra', ['q' => 'platani'], ['condivisa' => true]);
        $this->patchJson("/api/v1/viste/{$condivisa['id']}", ['predefinita' => true])->assertOk();

        $collega = \App\Models\User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $collega->assignRole('tecnico');
        $this->actingAsTenantUser($collega);

        // Per il collega la vista si vede, ma NON come predefinita: la scelta
        // di aprirla da sola e' personale del titolare, non della squadra
        $viste = collect($this->getJson('/api/v1/viste?pagina=censimento')->json('data'));
        $this->assertFalse($viste->firstWhere('nome', 'Della squadra')['predefinita']);
    }

    public function test_le_viste_non_escono_dall_organizzazione(): void
    {
        $this->salva('Riservata', extra: ['condivisa' => true]);

        [, $estraneo] = $this->createTenantUser();
        $this->actingAsTenantUser($estraneo);

        $this->assertSame([], $this->getJson('/api/v1/viste?pagina=censimento')->json('data'));
    }

    public function test_eliminare_la_propria_vista(): void
    {
        $vista = $this->salva('Da buttare');

        $this->deleteJson("/api/v1/viste/{$vista['id']}")->assertNoContent();

        $this->assertSame(0, SavedFilter::query()->count());
    }

    public function test_una_pagina_sconosciuta_viene_rifiutata(): void
    {
        $this->postJson('/api/v1/viste', [
            'pagina' => 'inventata', 'nome' => 'X', 'filtri' => [],
        ])->assertUnprocessable();
    }
}
