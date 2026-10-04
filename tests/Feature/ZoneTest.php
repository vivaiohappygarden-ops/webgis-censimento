<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\CatalogObjectType;
use App\Models\Client;
use App\Models\Issue;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Zone;
use App\Support\PerimetroZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Zone (punto 4 del committente, 04/10/2026): un'azienda con sedi in piu'
 * parti d'Italia divide il territorio in zone di committenti; un utente
 * assegnato a una zona vede e tocca solo quello che sta sotto quei
 * committenti (sedi, aree, elementi, mappa, lavori, segnalazioni,
 * trattamenti, valutazioni, foto, scadenze, documenti, scarico del telefono);
 * chi non ha zone e' centrale e vede tutto; le zone le gestisce solo la
 * sede centrale.
 */
class ZoneTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $centrale;

    private Area $areaNord;

    private Area $areaSud;

    private Client $nord;

    private Client $sud;

    private string $alberoNord;

    private string $alberoSud;

    private User $utenteNord;

    private Zone $zonaNord;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->organizzazione, $this->centrale] = $this->createTenantUser();
        $this->actingAsTenantUser($this->centrale);

        // Due committenti con il loro territorio: Nord e Sud
        $this->areaNord = $this->createArea($this->organizzazione, ['name' => 'Parco Nord']);
        $this->areaSud = $this->createArea($this->organizzazione, ['name' => 'Parco Sud']);
        $this->nord = $this->areaNord->locality->site->client;
        $this->sud = $this->areaSud->locality->site->client;
        $this->nord->update(['name' => 'Comune del Nord']);
        $this->sud->update(['name' => 'Comune del Sud']);
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->alberoNord = $this->albero($this->areaNord, $tipo, 'NORD-001');
        $this->alberoSud = $this->albero($this->areaSud, $tipo, 'SUD-001');

        // La zona Nord con il suo committente e il suo tecnico
        $this->zonaNord = Zone::create(['tenant_id' => $this->organizzazione->id, 'name' => 'Zona Nord']);
        $this->zonaNord->clients()->sync([$this->nord->id]);
        $this->utenteNord = User::factory()->create(['tenant_id' => $this->organizzazione->id, 'name' => 'Tecnico del Nord']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $this->utenteNord->assignRole('amministratore');
        $this->zonaNord->users()->sync([$this->utenteNord->id]);
        PerimetroZone::azzera();
    }

    private function albero(Area $area, CatalogObjectType $tipo, string $codice): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $area->id, 'object_type_id' => $tipo->id, 'census_code' => $codice, 'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    /** @return array{0: int, 1: int} la tessera (x, y) che contiene il punto, come in TileTest */
    private function tessera(float $lon, float $lat, int $zoom): array
    {
        $n = 2 ** $zoom;
        $latRad = deg2rad($lat);

        return [(int) floor(($lon + 180.0) / 360.0 * $n), (int) floor((1.0 - log(tan($latRad) + 1.0 / cos($latRad)) / M_PI) / 2.0 * $n)];
    }

    private function comeNord(): void
    {
        $this->actingAsTenantUser($this->utenteNord->fresh());
        PerimetroZone::azzera();
    }

    public function test_chi_e_di_zona_vede_solo_il_suo_territorio(): void
    {
        // Lavori, segnalazioni, valutazione, trattamento e foto a Sud, creati dalla sede centrale
        $ordineSud = $this->postJson('/api/v1/work-orders', ['title' => 'Potatura a Sud', 'client_id' => $this->sud->id, 'asset_ids' => [$this->alberoSud]])->assertCreated()->json('data');
        $ordineNord = $this->postJson('/api/v1/work-orders', ['title' => 'Potatura a Nord', 'client_id' => $this->nord->id, 'asset_ids' => [$this->alberoNord]])->assertCreated()->json('data');
        $this->postJson('/api/v1/issues', ['description' => 'Ramo a Sud', 'asset_id' => $this->alberoSud])->assertCreated();
        $this->postJson('/api/v1/issues', ['description' => 'Ramo a Nord', 'asset_id' => $this->alberoNord])->assertCreated();
        $this->postJson("/api/v1/assets/{$this->alberoSud}/assessments", ['assessment_type' => 'vta_visual', 'assessed_on' => '2026-09-01', 'failure_class' => 'C', 'prescriptions' => 'Rimonda a Sud', 'next_check_due' => now()->subDay()->toDateString()])->assertCreated();
        $this->postJson('/api/v1/phyto-treatments', ['area_id' => $this->areaSud->id, 'treated_on' => '2026-09-01', 'product_name' => 'Prodotto Sud', 'vegetation' => 'v', 'adversity' => 'a', 'method' => 'irrorazione', 'quantity' => 1, 'unit' => 'l'])->assertCreated();
        $fotoSud = $this->post("/api/v1/assets/{$this->alberoSud}/photos", ['photo' => UploadedFile::fake()->image('sud.jpg', 200, 150), 'category' => 'census'])->assertCreated()->json('data.id');

        $this->comeNord();

        // Anagrafiche e territorio
        $this->assertSame([$this->nord->id], collect($this->getJson('/api/v1/clients?per_page=100')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([$this->areaNord->id], collect($this->getJson('/api/v1/areas?per_page=100')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame(['NORD-001'], collect($this->getJson('/api/v1/assets?per_page=100')->assertOk()->json('data'))->pluck('census_code')->all());
        $this->assertSame(['NORD-001'], collect($this->getJson('/api/v1/assets?q=001&per_page=100')->assertOk()->json('data'))->pluck('census_code')->all());
        $this->getJson("/api/v1/assets/{$this->alberoSud}")->assertNotFound();
        $this->getJson("/api/v1/assets/{$this->alberoNord}")->assertOk();
        $this->getJson("/api/v1/clients/{$this->sud->id}")->assertNotFound();

        // La mappa: tessere e livelli (gli elementi stanno nello stesso punto)
        [$x, $y] = $this->tessera(9.1905, 45.4652, 15);
        $tessera = $this->get("/api/v1/tiles/assets/15/{$x}/{$y}")->assertOk()->getContent();
        $this->assertStringContainsString('NORD-001', $tessera);
        $this->assertStringNotContainsString('SUD-001', $tessera);
        $livelli = $this->getJson('/api/v1/tiles/livelli')->assertOk()->json('data');
        $this->assertSame(1, collect($livelli)->sum(fn ($l) => (int) ($l['n'] ?? 0)));

        // Esportazione: quello che si vede
        $csv = $this->get('/api/v1/exports/assets.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('NORD-001', $csv);
        $this->assertStringNotContainsString('SUD-001', $csv);

        // Lavori e segnalazioni
        $this->assertSame([$ordineNord['code']], collect($this->getJson('/api/v1/work-orders?per_page=100')->assertOk()->json('data'))->pluck('code')->all());
        $this->getJson("/api/v1/work-orders/{$ordineSud['id']}")->assertNotFound();
        $this->assertSame(['Ramo a Nord'], collect($this->getJson('/api/v1/issues?per_page=100')->assertOk()->json('data'))->pluck('description')->all());

        // Valutazioni, trattamenti, foto, scadenze
        $this->getJson("/api/v1/assets/{$this->alberoSud}/assessments")->assertNotFound();
        $this->assertCount(0, $this->getJson('/api/v1/phyto-treatments')->assertOk()->json('data'));
        $this->get("/api/v1/photos/{$fotoSud}/file")->assertNotFound();
        $oggi = $this->getJson('/api/v1/oggi')->assertOk()->json('data');
        $this->assertSame(0, $oggi['conteggi']['vta_scaduti'], 'la VTA scaduta a Sud non e\' una scadenza del Nord');
        $this->assertSame(0, $oggi['conteggi']['prescrizioni_aperte']);
        $this->assertSame(0, $this->getJson('/api/v1/vta/dashboard')->assertOk()->json('data.assessed'), 'la valutazione a Sud non conta');
        $this->assertSame(['Comune del Nord'], collect($this->getJson('/api/v1/committenti/riepilogo')->assertOk()->json('data'))->pluck('nome')->all());

        // Lo scarico del telefono porta solo il Nord
        $scarico = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json();
        $this->assertSame(['NORD-001'], array_column($scarico['assets'], 'census_code'));
        $this->assertSame([$this->areaNord->id], array_column($scarico['areas'], 'id'));

        // Il menu dice la zona
        $this->get('/oggi')->assertOk()->assertInertia(fn (Assert $p) => $p->where('auth.user.zone.0.name', 'Zona Nord'));
    }

    public function test_chi_e_di_zona_non_scrive_fuori_zona(): void
    {
        $this->comeNord();
        $tipo = CatalogObjectType::query()->where('code', 'P103108')->first();
        // L'area del Sud per lui non esiste: 404 o 422, mai 201
        $this->assertContains($this->postJson('/api/v1/assets', ['area_id' => $this->areaSud->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry()])->status(), [404, 422]);
        $this->assertNotSame(201, $this->postJson('/api/v1/work-orders', ['title' => 'Fuori zona', 'client_id' => $this->sud->id])->status());
        $this->assertNotSame(201, $this->postJson('/api/v1/work-orders', ['title' => 'Fuori zona', 'asset_ids' => [$this->alberoSud]])->status());
        $this->patchJson("/api/v1/assets/{$this->alberoSud}", ['notes' => 'x', 'version' => 1])->assertNotFound();
        $this->postJson("/api/v1/assets/{$this->alberoSud}/photos", ['photo' => UploadedFile::fake()->image('x.jpg', 100, 100), 'category' => 'census'])->assertNotFound();
        // Dentro la zona si lavora normalmente
        $this->postJson('/api/v1/work-orders', ['title' => 'In zona', 'client_id' => $this->nord->id, 'asset_ids' => [$this->alberoNord]])->assertCreated();
    }

    public function test_l_utente_centrale_vede_tutto_e_le_zone_si_gestiscono_solo_dalla_sede(): void
    {
        $this->assertSame(['NORD-001', 'SUD-001'], collect($this->getJson('/api/v1/assets?per_page=100&ordina=cartellino')->assertOk()->json('data'))->pluck('census_code')->sort()->values()->all());
        $this->get('/oggi')->assertOk()->assertInertia(fn (Assert $p) => $p->where('auth.user.zone', []));

        // La sede crea una zona Sud con committente e utente
        $utenteSud = User::factory()->create(['tenant_id' => $this->organizzazione->id, 'name' => 'Tecnico del Sud']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $utenteSud->assignRole('tecnico');
        $zona = $this->postJson('/api/v1/zone', ['name' => 'Zona Sud', 'code' => 'SUD', 'client_ids' => [$this->sud->id], 'user_ids' => [$utenteSud->id]])->assertCreated()->json('data');
        $this->assertSame(['Comune del Sud'], array_column($zona['committenti'], 'name'));
        $this->assertSame(['Tecnico del Sud'], array_column($zona['utenti'], 'name'));
        $elenco = $this->getJson('/api/v1/zone')->assertOk()->json('data');
        $this->assertSame(['Zona Nord', 'Zona Sud'], array_column($elenco['zone'], 'name'));
        $this->assertCount(2, $elenco['committenti']);
        $this->postJson('/api/v1/zone', ['name' => 'Zona Sud'])->assertStatus(422);

        // Il tecnico del Sud vede il Sud
        $this->actingAsTenantUser($utenteSud->fresh());
        PerimetroZone::azzera();
        $this->assertSame(['SUD-001'], collect($this->getJson('/api/v1/assets?per_page=100')->assertOk()->json('data'))->pluck('census_code')->all());
        // Un utente di zona, anche amministratore, non tocca le zone
        $this->comeNord();
        $this->getJson('/api/v1/zone')->assertForbidden();
        $this->patchJson("/api/v1/zone/{$zona['id']}", ['client_ids' => [$this->nord->id, $this->sud->id]])->assertForbidden();
        $this->get('/zone')->assertForbidden();

        // La sede toglie l'utente dalla zona Sud: torna centrale; poi elimina la zona
        $this->actingAsTenantUser($this->centrale);
        PerimetroZone::azzera();
        $this->deleteJson("/api/v1/zone/{$zona['id']}")->assertStatus(422);
        $this->patchJson("/api/v1/zone/{$zona['id']}", ['user_ids' => []])->assertOk();
        $this->actingAsTenantUser($utenteSud->fresh());
        PerimetroZone::azzera();
        $this->assertCount(2, $this->getJson('/api/v1/assets?per_page=100')->assertOk()->json('data'));
        $this->actingAsTenantUser($this->centrale);
        PerimetroZone::azzera();
        $this->deleteJson("/api/v1/zone/{$zona['id']}")->assertOk();
        $this->assertDatabaseMissing('zones', ['id' => $zona['id']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'zone.created']);
    }
}
