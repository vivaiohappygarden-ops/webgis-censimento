<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Support\AssetStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Gli stati di un elemento seguono il suo tipo (domanda del committente
 * 07/10/2026: "i parchi e le attrezzature hanno gli stessi stati delle
 * alberature?"): "morto in piedi" e "ceppaia" valgono solo per la
 * vegetazione, per arredi e attrezzature restano attivo e dismesso.
 */
class StatiPerTipoTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->actingAsTenantUser($this->utente);
    }

    private function crea(string $codiceTipo, array $extra = []): array
    {
        $tipo = $this->makeObjectType($this->organizzazione, 'P', $codiceTipo);

        return [$tipo, $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry(), ...$extra,
        ])];
    }

    public function test_la_regola_sta_in_un_posto_solo(): void
    {
        $this->assertTrue(AssetStatus::eVegetazione('P103108'));
        $this->assertTrue(AssetStatus::eVegetazione('S101016'));
        $this->assertFalse(AssetStatus::eVegetazione('P219012'));
        $this->assertFalse(AssetStatus::eVegetazione(null));
        foreach (array_keys(AssetStatus::LABELS) as $stato) {
            $this->assertTrue(AssetStatus::ammessoPer($stato, 'P103108'), "per un albero vale {$stato}");
        }
        $this->assertTrue(AssetStatus::ammessoPer('active', 'P219012'));
        $this->assertTrue(AssetStatus::ammessoPer('dismissed', 'P219012'));
        $this->assertTrue(AssetStatus::ammessoPer('removed', 'P219012'));
        $this->assertFalse(AssetStatus::ammessoPer('dead', 'P219012'));
        $this->assertFalse(AssetStatus::ammessoPer('stump', 'P214250'));
        $this->assertStringContainsString('vale solo per la vegetazione', AssetStatus::motivoNonAmmesso('dead'));
    }

    public function test_una_panchina_non_diventa_morta_in_piedi_ne_ceppaia_mentre_un_albero_si(): void
    {
        [, $panchina] = $this->crea('P219012');
        $panchina->assertCreated();
        $id = $panchina->json('data.id');
        $versione = $panchina->json('data.version');

        $this->patchJson("/api/v1/assets/{$id}", ['version' => $versione, 'status' => 'dead'])
            ->assertStatus(422)->assertJsonValidationErrors(['status'])
            ->assertJsonPath('errors.status.0', AssetStatus::motivoNonAmmesso('dead'));
        $this->patchJson("/api/v1/assets/{$id}", ['version' => $versione, 'status' => 'stump'])->assertStatus(422);
        $this->assertSame('active', Asset::query()->findOrFail($id)->status);

        // Dismettere si puo', e si torna attivi
        $this->patchJson("/api/v1/assets/{$id}", ['version' => $versione, 'status' => 'dismissed'])->assertOk();
        $this->assertSame('dismissed', Asset::query()->findOrFail($id)->status);

        // Nasce gia' sbagliata: no
        $this->crea('P224000', ['status' => 'stump'])[1]->assertStatus(422)->assertJsonValidationErrors(['status']);

        // Un albero invece muore in piedi
        [, $albero] = $this->crea('P103108');
        $this->patchJson('/api/v1/assets/'.$albero->json('data.id'), ['version' => $albero->json('data.version'), 'status' => 'dead'])->assertOk();
    }

    public function test_dal_campo_vale_la_stessa_regola(): void
    {
        $gioco = $this->makeObjectType($this->organizzazione, 'P', 'P214250');
        $comando = fn (array $payload) => [
            'idempotency_key' => (string) Str::uuid(), 'device_seq' => 1, 'type' => 'asset.create', 'entity_id' => (string) Str::uuid(),
            'payload' => ['area_id' => $this->area->id, 'object_type_id' => $gioco->id, ...$payload],
            'geom' => $this->pointGeometry(), 'client_ts' => now()->toIso8601String(),
        ];
        $lotto = fn (array $comandi) => ['batch_id' => (string) Str::uuid(), 'device_id' => 'dev-test-0001', 'schema' => 1, 'commands' => $comandi];

        $this->postJson('/api/v1/sync/batch', $lotto([$comando(['status' => 'dead'])]))
            ->assertOk()->assertJsonPath('results.0.status', 'rejected');
        $risposta = $this->postJson('/api/v1/sync/batch', $lotto([$comando(['status' => 'active'])]))
            ->assertOk()->assertJsonPath('results.0.status', 'applied');
        $id = $risposta->json('results.0.entity_id');

        // E nemmeno con un aggiornamento dello stato
        $this->postJson('/api/v1/sync/batch', $lotto([[
            'idempotency_key' => (string) Str::uuid(), 'device_seq' => 2, 'type' => 'asset.change_status', 'entity_id' => $id,
            'payload' => ['status' => 'stump'], 'base_version' => Asset::query()->findOrFail($id)->version, 'client_ts' => now()->toIso8601String(),
        ]]))->assertOk()->assertJsonPath('results.0.status', 'rejected');
        $this->assertSame('active', Asset::query()->findOrFail($id)->status);
    }
}
