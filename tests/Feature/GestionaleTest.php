<?php

namespace Tests\Feature;

use App\Jobs\SendToGestionale;
use App\Models\GestionaleDispatch;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class GestionaleTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    private $treeType;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->area = $this->createArea($this->organization);
        $this->treeType = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function configure(string $endpoint = 'https://giardini.esempio.it/?rest_route=/yourgarden/v1/sopralluoghi'): void
    {
        $this->putJson('/api/v1/gestionale/settings', [
            'endpoint' => $endpoint,
            'token' => 'ygw_super_segreto_1234',
        ])->assertOk();
    }

    private function createTreeAsset(): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => 'ALB-042',
            'geometry' => $this->pointGeometry(10.5421, 45.4712),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Pinus', 'species' => 'Pinus pinea', 'common_name' => 'Pino domestico'],
        ])->assertOk();

        return $id;
    }

    public function test_settings_are_saved_masked_and_admin_only(): void
    {
        // Endpoint non https rifiutato (salvo indirizzi locali di prova)
        $this->putJson('/api/v1/gestionale/settings', [
            'endpoint' => 'http://giardini.esempio.it/x', 'token' => 'ygw_x',
        ])->assertUnprocessable();

        $this->configure();
        $settings = $this->getJson('/api/v1/gestionale/settings')->assertOk()->json('data');
        $this->assertTrue($settings['configured']);
        // Il gettone non viaggia mai per intero verso il browser
        $this->assertSame('ygw_…1234', $settings['token_hint']);
        $this->assertStringNotContainsString('super_segreto', json_encode($settings));

        // Salvare senza gettone conserva quello esistente
        $this->putJson('/api/v1/gestionale/settings', [
            'endpoint' => 'https://giardini.esempio.it/nuovo', 'token' => null,
        ])->assertOk()->assertJsonPath('data.configured', true);

        // Il tecnico non tocca le impostazioni (solo amministratore)
        $tecnico = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $tecnico->assignRole('tecnico');
        $this->actingAsTenantUser($tecnico);
        $this->getJson('/api/v1/gestionale/settings')->assertForbidden();
    }

    public function test_dispatch_sends_payload_per_spec(): void
    {
        $this->configure();
        $asset = $this->createTreeAsset();

        Http::fake(['giardini.esempio.it/*' => Http::response([
            'ok' => true, 'id' => 213,
            'url' => 'https://giardini.esempio.it/?page_id=9&vista=segnalazioni&apri=213',
            'duplicate' => false,
        ], 201)]);

        $created = $this->postJson("/api/v1/assets/{$asset}/gestionale", [
            'request_type' => 'preventivo',
            'title' => 'Branca instabile',
            'description' => 'Branca primaria con crepa longitudinale.',
            'priority' => 'alta',
        ])->assertCreated()->json('data');

        $this->assertSame('WG-'.now('Europe/Rome')->year.'-000001', $created['external_ref']);

        // Con la coda sincrona dei test il job e' gia' stato eseguito
        $dispatch = GestionaleDispatch::query()->findOrFail($created['id']);
        $this->assertSame('sent', $dispatch->status);
        $this->assertSame('213', $dispatch->remote_id);
        $this->assertFalse($dispatch->is_duplicate);

        Http::assertSent(function ($request) use ($created) {
            $body = $request->data();

            return $request->hasHeader('X-YGC-Token', 'ygw_super_segreto_1234')
                && $body['external_ref'] === $created['external_ref']
                && $body['tipo_richiesta'] === 'preventivo'
                && $body['elemento']['codice'] === 'ALB-042'
                && $body['elemento']['tipo'] === 'albero'
                && $body['elemento']['specie'] === 'Pinus pinea'
                && abs($body['elemento']['latitudine'] - 45.4712) < 0.001
                && $body['problema']['titolo'] === 'Branca instabile'
                && $body['problema']['priorita'] === 'alta'
                && ! isset($body['foto']);
        });
    }

    public function test_photos_travel_base64_and_must_belong_to_asset(): void
    {
        Storage::fake('local');
        $this->configure();
        $asset = $this->createTreeAsset();

        Storage::disk()->put('photos/test/foto.jpg', 'contenuto-finto-jpeg');
        $photo = Photo::create([
            'tenant_id' => $this->organization->id,
            'asset_id' => $asset,
            's3_key' => 'photos/test/foto.jpg',
            'original_filename' => 'branca.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 20,
            'category' => 'defect',
        ]);

        Http::fake(['*' => Http::response(['ok' => true, 'id' => 1, 'duplicate' => false], 201)]);

        $this->postJson("/api/v1/assets/{$asset}/gestionale", [
            'request_type' => 'intervento',
            'title' => 'Con foto',
            'priority' => 'media',
            'photo_ids' => [strtoupper($photo->id)],
        ])->assertCreated();

        Http::assertSent(fn ($request) => $request->data()['foto'][0]['nome'] === 'branca.jpg'
            && $request->data()['foto'][0]['mime'] === 'image/jpeg'
            && base64_decode($request->data()['foto'][0]['base64']) === 'contenuto-finto-jpeg');

        // Una foto di un altro elemento viene rifiutata
        $otherAsset = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => 'ALB-043',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/assets/{$otherAsset}/gestionale", [
            'request_type' => 'intervento',
            'title' => 'Foto altrui',
            'priority' => 'media',
            'photo_ids' => [$photo->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('photo_ids');
    }

    public function test_duplicate_and_permanent_errors(): void
    {
        $asset = $this->createTreeAsset();

        // Le regole di Http::fake si accumulano: un host diverso per fase
        Http::fake([
            'dup.esempio.it/*' => Http::response(['ok' => true, 'id' => 99, 'duplicate' => true], 200),
            'ko.esempio.it/*' => Http::response('', 401),
            'ok.esempio.it/*' => Http::response(['ok' => true, 'id' => 7, 'duplicate' => true], 200),
        ]);

        // 200 con duplicate: consegnato comunque, segnato come gia' presente
        $this->configure('https://dup.esempio.it/?rest_route=/yourgarden/v1/sopralluoghi');
        $dup = $this->postJson("/api/v1/assets/{$asset}/gestionale", [
            'request_type' => 'intervento', 'title' => 'Doppione', 'priority' => 'media',
        ])->assertCreated()->json('data');
        $this->assertSame('sent', GestionaleDispatch::query()->find($dup['id'])->status);
        $this->assertTrue(GestionaleDispatch::query()->find($dup['id'])->is_duplicate);

        // 401: errore definitivo, un solo tentativo, messaggio chiaro
        $this->configure('https://ko.esempio.it/?rest_route=/yourgarden/v1/sopralluoghi');
        $ko = $this->postJson("/api/v1/assets/{$asset}/gestionale", [
            'request_type' => 'intervento', 'title' => 'Gettone errato', 'priority' => 'media',
        ])->assertCreated()->json('data');
        $failed = GestionaleDispatch::query()->find($ko['id']);
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('Gettone', $failed->last_error);
        Http::assertSentCount(2);

        // Il ritento rimette in coda (e con l'endpoint sano va a buon fine)
        $this->configure('https://ok.esempio.it/?rest_route=/yourgarden/v1/sopralluoghi');
        $this->postJson("/api/v1/gestionale/dispatches/{$ko['id']}/retry")->assertOk();
        $this->assertSame('sent', GestionaleDispatch::query()->find($ko['id'])->status);
    }

    public function test_server_errors_are_retried_not_final(): void
    {
        $this->configure();
        $asset = $this->createTreeAsset();

        $dispatch = GestionaleDispatch::create([
            'tenant_id' => $this->organization->id,
            'asset_id' => $asset,
            'external_ref' => 'WG-2026-000099',
            'request_type' => 'intervento',
            'title' => 'Salvataggio remoto rotto',
            'priority' => 'media',
            'created_by' => $this->user->id,
        ]);

        // 500: il job rilancia (la coda ritenta), lo stato resta pending
        Http::fake(['*' => Http::response(['ok' => false, 'errore' => 'salvataggio non riuscito'], 500)]);
        $job = new SendToGestionale($dispatch->id);
        try {
            $job->handle();
            $this->fail('Atteso il rilancio per il ritento.');
        } catch (\Illuminate\Http\Client\RequestException) {
            // atteso
        }
        $dispatch->refresh();
        $this->assertSame('pending', $dispatch->status);
        $this->assertSame(1, $dispatch->attempts);
        $this->assertStringContainsString('salvataggio', $dispatch->last_error);

        // Esauriti i tentativi: failed() lascia la riga consultabile
        $job->failed(new \RuntimeException('timeout'));
        $this->assertSame('failed', $dispatch->refresh()->status);
    }

    public function test_connection_probe_and_permissions(): void
    {
        Http::fake([
            'sano.esempio.it/*' => Http::response(['ok' => false, 'errore' => 'corpo non JSON'], 400),
            'ko.esempio.it/*' => Http::response('', 401),
        ]);

        // 400 = il gestionale ha letto la richiesta: gettone e indirizzo buoni
        $this->configure('https://sano.esempio.it/?rest_route=/yourgarden/v1/sopralluoghi');
        $this->postJson('/api/v1/gestionale/test')->assertOk()
            ->assertJsonPath('data.ok', true);

        $this->configure('https://ko.esempio.it/?rest_route=/yourgarden/v1/sopralluoghi');
        $this->postJson('/api/v1/gestionale/test')->assertOk()
            ->assertJsonPath('data.ok', false);

        // L'operatore legge gli invii ma non spedisce
        $asset = $this->createTreeAsset();
        $operatore = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operatore->assignRole('operatore');
        $this->actingAsTenantUser($operatore);
        $this->getJson('/api/v1/gestionale/dispatches')->assertOk();
        $this->postJson("/api/v1/assets/{$asset}/gestionale", [
            'request_type' => 'intervento', 'title' => 'X', 'priority' => 'media',
        ])->assertForbidden();

        // Un altro tenant non vede gli invii ne' le impostazioni altrui
        [, $foreign] = $this->createTenantUser();
        $this->actingAsTenantUser($foreign);
        $this->assertSame([], $this->getJson('/api/v1/gestionale/dispatches')->assertOk()->json('data'));
        $this->assertFalse($this->getJson('/api/v1/gestionale/settings')->assertOk()->json('data.configured'));
    }

    public function test_dispatch_requires_configuration(): void
    {
        $asset = $this->createTreeAsset();
        $this->postJson("/api/v1/assets/{$asset}/gestionale", [
            'request_type' => 'intervento', 'title' => 'X', 'priority' => 'media',
        ])->assertUnprocessable()->assertJsonValidationErrors('gestionale');
    }
}
