<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Mappa pubblica: pagina, riquadri vettoriali e separazione fra committenti.
 */
class PortaleMappaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organization;

    private $user;

    private Area $area;

    private Client $client;

    private $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->area = $this->createArea($this->organization);
        $this->client = Client::withoutGlobalScopes()->where('tenant_id', $this->organization->id)->firstOrFail();
        $this->client->forceFill([
            'public_slug' => 'mentana', 'public_enabled' => true, 'label_prefix' => 'MEN',
        ])->save();
        $this->type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    /** Riquadro cartografico che contiene il punto indicato. */
    private function riquadro(float $lon = 9.1905, float $lat = 45.4652, int $z = 16): string
    {
        $n = 2 ** $z;
        $x = (int) floor(($lon + 180) / 360 * $n);
        $y = (int) floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * $n);

        return "{$z}/{$x}/{$y}";
    }

    private function creaElemento(float $lon = 9.1905, float $lat = 45.4652, ?Area $area = null): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => ($area ?? $this->area)->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry($lon, $lat),
        ])->assertCreated()->json('data.id');
    }

    public function test_la_mappa_mostra_sfondi_e_legenda(): void
    {
        $this->creaElemento();

        $risposta = $this->get('/comune/mentana/mappa');

        $risposta->assertOk();
        $risposta->assertSee('Stradale');
        $risposta->assertSee('Satellite');
        $risposta->assertSee('Scura');
        // Legenda a quattro stati, senza "abbattuto"
        $risposta->assertSee('Sano');
        $risposta->assertSee('In cura');
        $risposta->assertSee('Da potare');
        $risposta->assertSee('In verifica');
        $risposta->assertDontSee('Abbattut');
    }

    public function test_senza_elementi_la_mappa_lo_dice(): void
    {
        $this->get('/comune/mentana/mappa')
            ->assertOk()
            ->assertSee('Non ci sono ancora elementi pubblicati');
    }

    public function test_il_riquadro_contiene_i_punti_del_committente(): void
    {
        $this->creaElemento();

        $risposta = $this->get('/comune/mentana/mappa/'.$this->riquadro().'.pbf');

        $risposta->assertOk();
        $this->assertSame('application/vnd.mapbox-vector-tile', $risposta->headers->get('Content-Type'));
        $this->assertGreaterThan(0, strlen($risposta->getContent()));
    }

    public function test_il_riquadro_non_porta_gli_elementi_di_un_altro_committente(): void
    {
        // Guidonia ha un elemento, Mentana no: il riquadro di Mentana su
        // quel punto deve restare vuoto
        $altraArea = $this->createArea($this->organization, ['name' => 'Area Guidonia']);
        $altro = Client::withoutGlobalScopes()->findOrFail($altraArea->locality->site->client_id);
        $altro->forceFill(['public_slug' => 'guidonia', 'public_enabled' => true])->save();

        $this->creaElemento(area: $altraArea);

        $this->get('/comune/mentana/mappa/'.$this->riquadro().'.pbf')->assertNoContent();
        $this->get('/comune/guidonia/mappa/'.$this->riquadro().'.pbf')->assertOk();
    }

    public function test_l_elemento_nascosto_non_finisce_nel_riquadro(): void
    {
        $id = $this->creaElemento();

        $this->get('/comune/mentana/mappa/'.$this->riquadro().'.pbf')->assertOk();

        $this->patchJson("/api/v1/assets/{$id}", ['public_hidden' => true])->assertOk();

        $this->get('/comune/mentana/mappa/'.$this->riquadro().'.pbf')->assertNoContent();
    }

    public function test_il_riquadro_rifiuta_coordinate_impossibili(): void
    {
        $this->get('/comune/mentana/mappa/16/999999/1.pbf')->assertNotFound();
        $this->get('/comune/mentana/mappa/30/1/1.pbf')->assertNotFound();
    }

    public function test_il_pannello_della_mappa_chiede_solo_la_scheda(): void
    {
        $this->creaElemento();

        $intera = $this->get('/comune/mentana/elemento/MEN-0001');
        $intera->assertOk()->assertSee('<!DOCTYPE html>', false);

        $riquadro = $this->get('/comune/mentana/elemento/MEN-0001?riquadro=1');
        $riquadro->assertOk()->assertDontSee('<!DOCTYPE html>', false);
        $riquadro->assertSee('MEN-0001');
    }

    public function test_lo_sfondo_stradale_non_usa_i_server_di_prova_di_openstreetmap(): void
    {
        $stradale = collect(config('portal.basemaps'))->firstWhere('id', 'stradale');

        // La politica d'uso di openstreetmap.org esclude i siti pubblici:
        // quelle tessere sono per provare, non per un portale di un Comune
        $this->assertStringNotContainsString('tile.openstreetmap.org', (string) $stradale['url']);
        $this->assertNotEmpty($stradale['url']);
        // I dati restano di OpenStreetMap e vanno citati comunque
        $this->assertStringContainsString('OpenStreetMap', (string) $stradale['attribuzione']);
    }

    public function test_uno_sfondo_senza_indirizzo_sparisce_dal_selettore(): void
    {
        config(['portal.basemaps' => [
            ['id' => 'stradale', 'nome' => 'Stradale', 'url' => 'https://esempio/{z}/{x}/{y}.png', 'attribuzione' => 'x', 'scuro' => false],
            ['id' => 'satellite', 'nome' => 'Satellite', 'url' => '', 'attribuzione' => 'y', 'scuro' => true],
        ]]);

        $sfondi = \App\Services\Portale\PortalExtent::sfondi();

        $this->assertCount(1, $sfondi);
        $this->assertSame('stradale', $sfondi[0]['id']);
    }
}
