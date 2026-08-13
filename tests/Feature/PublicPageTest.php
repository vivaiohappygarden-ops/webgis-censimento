<?php

namespace Tests\Feature;

use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PublicPageTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $assetId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organization, $this->user] = $this->createTenantUser();
        $area = $this->createArea($this->organization, ['name' => 'Parco delle Rose']);
        $type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);

        $this->assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'ALB-QR-1',
            'geometry' => $this->pointGeometry(),
            'notes' => 'NOTA INTERNA RISERVATA',
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$this->assetId}", [
            'tree' => [
                'genus' => 'Quercus', 'species' => 'Quercus robur', 'common_name' => 'Farnia',
                'height_m' => 18, 'is_dedicated' => true,
                'dedicated_to' => ['name' => 'Maria Rossi', 'occasion' => 'Centenario della fondazione'],
            ],
        ])->assertOk();
    }

    public function test_public_page_lifecycle(): void
    {
        // Prima dell'attivazione niente cartellino e nessuna pagina
        $this->get("/api/v1/assets/{$this->assetId}/public-tag", ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $token = $this->postJson("/api/v1/assets/{$this->assetId}/public-page")
            ->assertOk()->json('data.public_token');
        $this->assertNotNull($token);

        // La pagina si apre SENZA alcun accesso e mostra solo dati divulgativi
        $page = $this->get("/p/{$token}");
        $page->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('Farnia', $html);
        $this->assertStringContainsString('Quercus robur', $html);
        $this->assertStringContainsString('Parco delle Rose', $html);
        $this->assertStringContainsString('Maria Rossi', $html);
        $this->assertStringNotContainsString('NOTA INTERNA RISERVATA', $html);
        $this->assertStringNotContainsString('ALB-QR-1', $html);

        // Il cartellino è un PDF con il QR
        $tag = $this->get("/api/v1/assets/{$this->assetId}/public-tag")->assertOk();
        $this->assertStringStartsWith('%PDF-', $tag->getContent());

        // Riattivare non rigenera il gettone (i QR stampati restano validi)
        $this->assertSame($token, $this->postJson("/api/v1/assets/{$this->assetId}/public-page")->json('data.public_token'));

        // La disattivazione spegne la pagina
        $this->deleteJson("/api/v1/assets/{$this->assetId}/public-page")->assertNoContent();
        $this->get("/p/{$token}")->assertNotFound();
    }

    public function test_public_photo_streams_only_when_present(): void
    {
        $token = $this->postJson("/api/v1/assets/{$this->assetId}/public-page")->json('data.public_token');

        $this->get("/p/{$token}/foto")->assertNotFound();

        $this->post("/api/v1/assets/{$this->assetId}/photos", [
            'photo' => UploadedFile::fake()->image('quercia.jpg', 300, 200),
            'category' => 'census',
        ])->assertCreated();

        $photo = $this->get("/p/{$token}/foto")->assertOk();
        $this->assertStringStartsWith('image/', $photo->headers->get('Content-Type'));
    }

    public function test_tokens_are_not_guessable_and_permissions_hold(): void
    {
        // Gettone inesistente: 404 senza indizi
        $this->get('/p/'.str_repeat('a', 32))->assertNotFound();

        // Senza il permesso di modifica non si attiva né si spegne
        $bare = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        $this->actingAsTenantUser($bare);
        $this->postJson("/api/v1/assets/{$this->assetId}/public-page")->assertForbidden();
        $this->deleteJson("/api/v1/assets/{$this->assetId}/public-page")->assertForbidden();

        // Un elemento eliminato spegne la pagina anche col gettone valido
        $this->actingAsTenantUser($this->user);
        $token = $this->postJson("/api/v1/assets/{$this->assetId}/public-page")->json('data.public_token');
        Asset::query()->findOrFail($this->assetId)->delete();
        $this->get("/p/{$token}")->assertNotFound();
    }
}
