<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Client;
use App\Models\Issue;
use App\Models\Locality;
use App\Models\Photo;
use App\Models\Site;
use App\Models\User;
use App\Support\Geometry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PortalRequestTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $admin;

    private $client;

    private $area;

    private $portalUser;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organization, $this->admin] = $this->createTenantUser();

        $this->client = Client::create(['tenant_id' => $this->organization->id, 'name' => 'Comune Richieste', 'client_type' => 'public']);
        $site = Site::create(['tenant_id' => $this->organization->id, 'client_id' => $this->client->id, 'name' => 'Sede']);
        $locality = Locality::create(['tenant_id' => $this->organization->id, 'site_id' => $site->id, 'name' => 'Localita']);
        $this->area = Area::create([
            'tenant_id' => $this->organization->id,
            'locality_id' => $locality->id,
            'name' => 'Giardino Comunale',
            'geom' => Geometry::toEwkb($this->squarePolygon(), forceMultiPolygon: true),
        ]);

        $this->portalUser = User::factory()->create([
            'tenant_id' => $this->organization->id,
            'user_type' => 'client_portal',
            'client_id' => $this->client->id,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $this->portalUser->assignRole('cliente');
    }

    public function test_client_sends_a_request_with_photos_and_it_becomes_an_issue(): void
    {
        $this->actingAsTenantUser($this->portalUser);

        $body = $this->post('/api/v1/portal/requests', [
            'description' => 'Ramo pericolante sul vialetto principale.',
            'area_id' => $this->area->id,
            'severity' => 'high',
            'photos' => [
                UploadedFile::fake()->image('ramo1.jpg', 800, 600),
                UploadedFile::fake()->image('ramo2.jpg', 640, 480),
            ],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('open', $body['status']);
        $this->assertSame('high', $body['severity']);
        $this->assertSame('Giardino Comunale', $body['area']);
        $this->assertSame(2, $body['photos_count']);

        // Lato staff: è una segnalazione vera, con SLA e canale portale
        $issue = Issue::withoutGlobalScopes()->findOrFail($body['id']);
        $this->assertSame('client_portal', $issue->channel);
        $this->assertSame('client', $issue->reporter_type);
        $this->assertSame('Comune Richieste', $issue->reporter_name);
        $this->assertNotNull($issue->sla_due_at);
        $this->assertNotNull($issue->taken_charge_due_at);

        $photos = Photo::withoutGlobalScopes()
            ->where('subject_type', 'issue')->where('subject_id', $issue->id)->get();
        $this->assertCount(2, $photos);
        Storage::disk()->assertExists($photos->first()->s3_key);

        // Lo staff vede foto e richiesta nell'elenco segnalazioni
        $this->actingAsTenantUser($this->admin);
        $row = collect($this->getJson('/api/v1/issues')->assertOk()->json('data'))
            ->firstWhere('id', $issue->id);
        $this->assertCount(2, $row['photos']);
    }

    public function test_request_validation_and_area_ownership(): void
    {
        $this->actingAsTenantUser($this->portalUser);

        // Descrizione obbligatoria
        $this->postJson('/api/v1/portal/requests', ['severity' => 'low'])
            ->assertUnprocessable()->assertJsonValidationErrors('description');

        // "critical" è riservata allo staff
        $this->postJson('/api/v1/portal/requests', [
            'description' => 'X', 'severity' => 'critical',
        ])->assertUnprocessable();

        // Area di un altro cliente rifiutata
        $otherArea = $this->createArea($this->organization, ['name' => 'Area altrui']);
        $this->postJson('/api/v1/portal/requests', [
            'description' => 'Problema', 'area_id' => $otherArea->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('area_id');

        // Massimo 3 foto
        $this->post('/api/v1/portal/requests', [
            'description' => 'Troppe foto',
            'photos' => [
                UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'),
                UploadedFile::fake()->image('c.jpg'), UploadedFile::fake()->image('d.jpg'),
            ],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_clients_see_only_their_own_requests(): void
    {
        $this->actingAsTenantUser($this->portalUser);
        $this->postJson('/api/v1/portal/requests', ['description' => 'La mia richiesta.'])->assertCreated();

        // Un secondo utente dello STESSO cliente vede la richiesta del collega
        $peer = User::factory()->create([
            'tenant_id' => $this->organization->id,
            'user_type' => 'client_portal',
            'client_id' => $this->client->id,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $peer->assignRole('cliente');
        $this->actingAsTenantUser($peer);
        $this->assertCount(1, $this->getJson('/api/v1/portal/requests')->assertOk()->json('data'));

        // Un utente di un ALTRO cliente non vede nulla
        $otherClient = Client::create(['tenant_id' => $this->organization->id, 'name' => 'Altro Cliente', 'client_type' => 'private']);
        $stranger = User::factory()->create([
            'tenant_id' => $this->organization->id,
            'user_type' => 'client_portal',
            'client_id' => $otherClient->id,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $stranger->assignRole('cliente');
        $this->actingAsTenantUser($stranger);
        $this->assertCount(0, $this->getJson('/api/v1/portal/requests')->assertOk()->json('data'));

        // Un utente non collegato a un cliente riceve l'errore parlante
        $unlinked = User::factory()->create(['tenant_id' => $this->organization->id, 'user_type' => 'client_portal']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $unlinked->assignRole('cliente');
        $this->actingAsTenantUser($unlinked);
        $this->postJson('/api/v1/portal/requests', ['description' => 'X'])->assertUnprocessable();

        // Lo staff senza portal.view non usa gli endpoint del portale
        [, $tech] = [null, User::factory()->create(['tenant_id' => $this->organization->id])];
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $tech->assignRole('tecnico');
        $this->actingAsTenantUser($tech);
        $this->getJson('/api/v1/portal/requests')->assertForbidden();
    }

    public function test_resolution_notes_appear_only_when_resolved(): void
    {
        $this->actingAsTenantUser($this->portalUser);
        $id = $this->postJson('/api/v1/portal/requests', ['description' => 'Siepe da controllare.'])
            ->assertCreated()->json('data.id');

        // Lo staff prende in carico e risolve con una nota
        $this->actingAsTenantUser($this->admin);
        $this->patchJson("/api/v1/issues/{$id}", ['status' => 'in_charge'])->assertOk();

        $this->actingAsTenantUser($this->portalUser);
        $pending = collect($this->getJson('/api/v1/portal/requests')->json('data'))->firstWhere('id', $id);
        $this->assertNull($pending['resolution_notes']);

        $this->actingAsTenantUser($this->admin);
        $this->patchJson("/api/v1/issues/{$id}", [
            'status' => 'resolved', 'resolution_notes' => 'Potata e messa in sicurezza.',
        ])->assertOk();

        $this->actingAsTenantUser($this->portalUser);
        $resolved = collect($this->getJson('/api/v1/portal/requests')->json('data'))->firstWhere('id', $id);
        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('Potata e messa in sicurezza.', $resolved['resolution_notes']);
    }
}
