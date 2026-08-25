<?php

namespace Tests\Feature;

use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    private $treeType;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser(role: 'operatore');
        $this->area = $this->createArea($this->organization);
        $this->treeType = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function batchPayload(array $commands): array
    {
        return [
            'batch_id' => (string) Str::uuid(),
            'device_id' => 'dev-test-0001',
            'schema' => 1,
            'commands' => $commands,
        ];
    }

    private function createCommand(?string $entityId = null, array $overrides = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 1,
            'type' => 'asset.create',
            'entity_id' => $entityId ?? (string) Str::uuid(),
            'payload' => [
                'area_id' => $this->area->id,
                'object_type_id' => $this->treeType->id,
                'census_code' => 'SYNC-0001',
            ],
            'geom' => [
                'type' => 'Point',
                'coordinates' => [9.1905, 45.4652],
                'properties' => ['gps_accuracy_m' => 2.1, 'survey_method' => 'gps'],
            ],
            'client_ts' => now()->toIso8601String(),
            ...$overrides,
        ];
    }

    public function test_bootstrap_returns_working_set_with_cursor(): void
    {
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([$this->createCommand()]))->assertOk();

        $data = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json();

        $this->assertIsInt($data['cursor']);
        $this->assertGreaterThan(0, $data['cursor']);
        $this->assertNotEmpty($data['catalog_version']);
        $this->assertCount(1, $data['areas']);
        $this->assertCount(1, $data['assets']);
        $this->assertSame('SYNC-0001', $data['assets'][0]['census_code']);
        $this->assertNotNull($data['assets'][0]['tree']);
        $this->assertNotEmpty($data['catalog']['object_types']);
    }

    public function test_create_command_uses_client_uuid_and_creates_tree(): void
    {
        $entityId = (string) Str::uuid();

        $response = $this->postJson('/api/v1/sync/batch', $this->batchPayload([
            $this->createCommand($entityId),
        ]))->assertOk();

        $response->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.entity_id', $entityId)
            ->assertJsonPath('results.0.version', 1);

        $asset = Asset::query()->findOrFail($entityId);
        $this->assertSame('SYNC-0001', $asset->census_code);
        $this->assertEquals(2.1, $asset->gps_accuracy_m);
        $this->assertNotNull($asset->tree);
    }

    public function test_replay_returns_original_result_without_reapplying(): void
    {
        $command = $this->createCommand();
        $payload = $this->batchPayload([$command]);

        $this->postJson('/api/v1/sync/batch', $payload)->assertOk()
            ->assertJsonPath('results.0.status', 'applied');

        // Stesso batch reinviato (retry dopo timeout): esito originale incapsulato
        $this->postJson('/api/v1/sync/batch', $payload)->assertOk()
            ->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.0.original.status', 'applied');

        $this->assertSame(1, Asset::query()->count());
    }

    public function test_update_measures_bumps_version_and_stale_base_version_conflicts(): void
    {
        $entityId = (string) Str::uuid();
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([$this->createCommand($entityId)]))->assertOk();

        $this->postJson('/api/v1/sync/batch', $this->batchPayload([[
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 2,
            'type' => 'asset.update_measures',
            'entity_id' => $entityId,
            'base_version' => 1,
            'payload' => ['height_m' => 14.5, 'dbh_cm' => 62, 'species' => 'Tilia cordata'],
        ]]))->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.version', 2);

        // Un secondo device con base_version stantia riceve il conflitto con yours/theirs
        $conflict = $this->postJson('/api/v1/sync/batch', $this->batchPayload([[
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 3,
            'type' => 'asset.update_measures',
            'entity_id' => $entityId,
            'base_version' => 1,
            'payload' => ['height_m' => 14.2],
        ]]))->assertOk()->json('results.0');

        $this->assertSame('conflict', $conflict['status']);
        $this->assertSame('VERSION_MISMATCH', $conflict['code']);
        $this->assertSame(1, $conflict['yours']['base_version']);
        $this->assertSame(2, $conflict['theirs']['version']);
        $this->assertEquals(14.5, $conflict['theirs']['fields']['height_m']);
    }

    public function test_la_storia_racconta_le_misure_sincronizzate_con_i_valori_precedenti(): void
    {
        $entityId = (string) Str::uuid();
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([$this->createCommand($entityId)]))->assertOk();

        $this->postJson('/api/v1/sync/batch', $this->batchPayload([[
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 2,
            'type' => 'asset.update_measures',
            'entity_id' => $entityId,
            'base_version' => 1,
            'payload' => ['height_m' => 12, 'species' => 'Tilia cordata'],
        ]]))->assertOk()->assertJsonPath('results.0.status', 'applied');

        $this->postJson('/api/v1/sync/batch', $this->batchPayload([[
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 3,
            'type' => 'asset.update_measures',
            'entity_id' => $entityId,
            'base_version' => 2,
            'payload' => ['height_m' => 14.5],
        ]]))->assertOk()->assertJsonPath('results.0.status', 'applied');

        $storia = $this->getJson("/api/v1/assets/{$entityId}/versioni")->assertOk()->json('data');

        // Anche nel percorso di sincronizzazione la fotografia scatta PRIMA
        // del salvataggio dell'albero: due sincronizzazioni = due revisioni,
        // la prima con la compilazione iniziale, l'ultima con 12 -> 14.5.
        // Con l'ordine invertito le fotografie conterrebbero gia' i valori
        // nuovi: la modifica scivolerebbe nella revisione precedente e una
        // delle due sparirebbe (qui ne resterebbe una sola).
        $this->assertCount(2, $storia);

        $campi = collect($storia[0]['modifiche'])->keyBy('campo');
        $this->assertEqualsWithDelta(12, (float) $campi['Altezza (m)']['prima'], 0.001);
        $this->assertEqualsWithDelta(14.5, (float) $campi['Altezza (m)']['dopo'], 0.001);
        $this->assertSame($this->user->name, $storia[0]['chi']);

        $inizio = collect($storia[1]['modifiche'])->keyBy('campo');
        $this->assertNull($inizio['Altezza (m)']['prima']);
        $this->assertSame('Tilia cordata', $inizio['Specie']['dopo']);
    }

    public function test_unknown_command_type_is_rejected_not_dropped(): void
    {
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([
            $this->createCommand(overrides: ['type' => 'asset.teleport']),
        ]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'UNKNOWN_TYPE');
    }

    public function test_role_without_create_permission_gets_rejected_forbidden(): void
    {
        // Un utente che può leggere il censimento ma non creare elementi
        // (il ruolo cliente ora vive solo nel portale, fuori dalla sync)
        $reader = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $reader->givePermissionTo('assets.view');
        $this->actingAsTenantUser($reader);

        $this->postJson('/api/v1/sync/batch', $this->batchPayload([$this->createCommand()]))
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'FORBIDDEN');
    }

    public function test_changes_returns_upserts_and_tombstones_with_advancing_cursor(): void
    {
        $entityId = (string) Str::uuid();
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([$this->createCommand($entityId)]))->assertOk();

        $first = $this->getJson('/api/v1/sync/changes?cursor=0')->assertOk()->json();
        $upserts = collect($first['changes'])->where('op', 'upsert')->where('table', 'assets');
        $this->assertTrue($upserts->contains(fn ($c) => $c['row']['id'] === $entityId));
        $this->assertGreaterThan(0, $first['cursor']);

        // Nessuna modifica dopo il cursore: delta vuoto
        $this->getJson('/api/v1/sync/changes?cursor='.$first['cursor'])
            ->assertOk()->assertJsonCount(0, 'changes');

        // La cancellazione (soft delete) viaggia come tombstone
        Asset::query()->findOrFail($entityId)->delete();
        $second = $this->getJson('/api/v1/sync/changes?cursor='.$first['cursor'])->assertOk()->json();
        $tombstones = collect($second['changes'])->where('op', 'delete')->where('table', 'assets');
        $this->assertTrue($tombstones->contains(fn ($c) => $c['id'] === $entityId));
    }

    public function test_sync_is_tenant_isolated(): void
    {
        $entityId = (string) Str::uuid();
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([$this->createCommand($entityId)]))->assertOk();

        [, $otherUser] = $this->createTenantUser(role: 'operatore');
        $this->actingAsTenantUser($otherUser);

        // L'altro tenant non vede né il working set né l'asset nel delta
        $this->assertCount(0, $this->getJson('/api/v1/sync/bootstrap')->json('assets'));
        $this->assertCount(0, $this->getJson('/api/v1/sync/changes?cursor=0')->json('changes'));

        // E un comando sul suo asset viene respinto come inesistente
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([[
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 1,
            'type' => 'asset.change_status',
            'entity_id' => $entityId,
            'base_version' => 1,
            'payload' => ['status' => 'removed'],
        ]]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'NOT_FOUND');
    }

    public function test_tag_associate_and_working_set_tags(): void
    {
        $entityId = (string) Str::uuid();
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([$this->createCommand($entityId)]))->assertOk();

        // Associazione da scansione in campo
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([[
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 2,
            'type' => 'tag.associate',
            'entity_id' => $entityId,
            'payload' => ['uid' => 'QR-000123', 'tag_type' => 'qr'],
        ]]))->assertOk()->assertJsonPath('results.0.status', 'applied');

        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $entityId, 'uid' => 'QR-000123', 'tag_type' => 'qr', 'status' => 'active',
        ]);

        // Ri-associazione allo stesso elemento (comando diverso): idempotente, nessun doppione
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([[
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 3,
            'type' => 'tag.associate',
            'entity_id' => $entityId,
            'payload' => ['uid' => 'QR-000123', 'tag_type' => 'qr'],
        ]]))->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->assertSame(1, \App\Models\AssetTag::query()->count());

        // Stesso tag su un altro elemento: rifiutato con il codice dell'elemento occupante
        $otherId = (string) Str::uuid();
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([
            $this->createCommand($otherId, ['payload' => [
                'area_id' => $this->area->id,
                'object_type_id' => $this->treeType->id,
                'census_code' => 'SYNC-0002',
            ]]),
        ]))->assertOk();
        $conflictResult = $this->postJson('/api/v1/sync/batch', $this->batchPayload([[
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 4,
            'type' => 'tag.associate',
            'entity_id' => $otherId,
            'payload' => ['uid' => 'QR-000123', 'tag_type' => 'qr'],
        ]]))->assertOk()->json('results.0');
        $this->assertSame('rejected', $conflictResult['status']);
        $this->assertSame('TAG_IN_USE', $conflictResult['code']);
        $this->assertStringContainsString('SYNC-0001', $conflictResult['message']);

        // I tag attivi viaggiano dentro la riga asset del working set
        $assets = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('assets');
        $withTag = collect($assets)->firstWhere('id', $entityId);
        $this->assertCount(1, $withTag['tags']);
        $this->assertSame('QR-000123', $withTag['tags'][0]['uid']);

        // E l'associazione produce una riga di delta per l'elemento
        $changes = $this->getJson('/api/v1/sync/changes?cursor=0')->assertOk()->json('changes');
        $this->assertTrue(collect($changes)->contains(
            fn ($c) => $c['op'] === 'upsert' && ($c['row']['id'] ?? null) === $entityId && count($c['row']['tags']) === 1,
        ));
    }

    public function test_invalid_geom_properties_are_rejected_not_internal_error(): void
    {
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([
            $this->createCommand(overrides: [
                'geom' => [
                    'type' => 'Point',
                    'coordinates' => [9.19, 45.46],
                    'properties' => ['survey_method' => 'piccione_viaggiatore'],
                ],
            ]),
        ]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'VALIDATION_FAILED');
    }

    public function test_changes_can_fetch_specific_ids_outside_cursor(): void
    {
        $entityId = (string) Str::uuid();
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([$this->createCommand($entityId)]))->assertOk();

        // Cursore già oltre la creazione: senza ids il delta è vuoto
        $cursor = $this->getJson('/api/v1/sync/changes?cursor=0')->json('cursor');
        $this->getJson("/api/v1/sync/changes?cursor={$cursor}")
            ->assertOk()->assertJsonCount(0, 'changes');

        // Con ids espliciti la riga viene servita comunque (recupero record dirty saltati)
        $data = $this->getJson("/api/v1/sync/changes?cursor={$cursor}&ids[]={$entityId}")
            ->assertOk()->json();
        $this->assertCount(1, $data['changes']);
        $this->assertSame($entityId, $data['changes'][0]['row']['id']);
        $this->assertSame($cursor, $data['cursor']);
    }

    public function test_envelope_validation(): void
    {
        // Schema client troppo vecchio: 426, la coda non viene toccata
        $payload = $this->batchPayload([$this->createCommand()]);
        $payload['schema'] = 0;
        $this->postJson('/api/v1/sync/batch', $payload)->assertStatus(426);

        // Batch oltre il limite: errore di envelope, non esiti per comando
        $tooMany = $this->batchPayload(array_map(
            fn () => $this->createCommand(),
            range(1, 201),
        ));
        $this->postJson('/api/v1/sync/batch', $tooMany)->assertUnprocessable();
    }
}
