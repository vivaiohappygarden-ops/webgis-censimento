<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Tree;
use App\Models\User;
use App\Models\Zone;
use App\Services\Vta\BersagliProposti;
use App\Support\Geometry;
use App\Support\PerimetroZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * I bersagli che il censimento propone da solo nella scheda VTA (richiesta del
 * committente 07/10/2026): le aree in cui l'albero sta e gli elementi censiti
 * entro il suo raggio di caduta, senza la vegetazione e senza l'archivio.
 */
class BersagliPropostiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private const LON = 9.1905;

    private const LAT = 45.4652;

    // Un metro in gradi alla latitudine di prova (45,47 gradi nord)
    private const M_LAT = 1 / 111320;

    private const M_LON = 1 / (111320 * 0.7013);

    private $organizzazione;

    private $utente;

    private Area $parco;

    private string $albero;

    /** @var array<string, \App\Models\CatalogObjectType> un tipo per codice: il catalogo non ammette doppioni */
    private array $tipi = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->actingAsTenantUser($this->utente);
        // Il parco giochi e' l'area della scheda e il suo poligono contiene l'albero
        $this->parco = $this->createArea($this->organizzazione, ['name' => 'Parco giochi di via Verdi', 'area_type' => 'functional']);
        $this->albero = $this->elemento('P103108', 'ALB-0001', $this->punto(0, 0), area: $this->parco);
        Tree::query()->where('asset_id', $this->albero)->update(['height_m' => 10]);
    }

    /** @return array{type: string, coordinates: array} */
    private function punto(float $estM, float $nordM): array
    {
        return ['type' => 'Point', 'coordinates' => [self::LON + $estM * self::M_LON, self::LAT + $nordM * self::M_LAT]];
    }

    /** Un quadrato di lato 2*$semilatoM centrato sull'albero. */
    private function quadrato(float $semilatoM): array
    {
        $o = self::LON - $semilatoM * self::M_LON;
        $e = self::LON + $semilatoM * self::M_LON;
        $s = self::LAT - $semilatoM * self::M_LAT;
        $n = self::LAT + $semilatoM * self::M_LAT;

        return ['type' => 'Polygon', 'coordinates' => [[[$o, $s], [$e, $s], [$e, $n], [$o, $n], [$o, $s]]]];
    }

    private function elemento(string $codiceTipo, string $cartellino, array $geometria, string $nomeTipo = 'Tipo oggetto di test', ?Area $area = null, string $stato = 'active'): string
    {
        $geo = $geometria['type'] === 'Point' ? 'P' : ($geometria['type'] === 'LineString' ? 'L' : 'S');
        $tipo = $this->tipi[$codiceTipo] ??= $this->makeObjectType($this->organizzazione, $geo, $codiceTipo, ['name' => $nomeTipo]);

        $id = $this->postJson('/api/v1/assets', [
            'area_id' => ($area ?? $this->parco)->id, 'object_type_id' => $tipo->id, 'census_code' => $cartellino, 'geometry' => $geometria,
        ])->assertCreated()->json('data.id');
        if ($stato !== 'active') {
            Asset::query()->whereKey($id)->update(['status' => $stato]);
        }

        return $id;
    }

    private function proposte(array $parametri = []): array
    {
        return $this->getJson("/api/v1/assets/{$this->albero}/bersagli-proposti?".http_build_query($parametri))->assertOk()->json('data');
    }

    public function test_propone_le_aree_in_cui_l_albero_sta_e_gli_elementi_nel_raggio_di_caduta(): void
    {
        $panchina = $this->elemento('P219012', 'PAN-01', $this->punto(4, 0), 'panchina in legno - punto');
        $gioco = $this->elemento('P214250', 'GIO-01', $this->punto(0, 8), 'gioco singolo');
        $vialetto = $this->elemento('L205000', 'VIA-01', ['type' => 'LineString', 'coordinates' => [
            [self::LON - 20 * self::M_LON, self::LAT - 3 * self::M_LAT], [self::LON + 20 * self::M_LON, self::LAT - 3 * self::M_LAT],
        ]], 'percorso/sentiero pavimentato');
        $areaGioco = $this->elemento('S327552', 'AG-01', $this->quadrato(12), 'area gioco');
        // Fuori: un altro albero, il prato in cui sta, un elemento troppo lontano,
        // una panchina dismessa, una ceppaia, un limite di area di gestione
        $this->elemento('P103108', 'ALB-0002', $this->punto(-3, 0));
        $this->elemento('S101016', 'PRA-01', $this->quadrato(30), 'prato in erba');
        $lontana = $this->elemento('P219004', 'PAN-99', $this->punto(25, 0), 'panchina in pietra - punto');
        $this->elemento('P219010', 'PAN-DIS', $this->punto(2, 0), 'panchina in cemento - punto', stato: 'dismissed');
        $this->elemento('S325500', 'LIM-01', $this->quadrato(40), 'limite area di gestione');
        // Un'altra area che contiene l'albero senza essere la sua, una prevista (non ancora esiste) e una lontana
        $this->createArea($this->organizzazione, ['name' => 'Giardino della scuola']);
        $this->createArea($this->organizzazione, ['name' => 'Ampliamento previsto', 'status' => 'planned']);
        $this->createArea($this->organizzazione, ['name' => 'Parco Nord', 'geom' => Geometry::toEwkb([
            'type' => 'Polygon', 'coordinates' => [[[9.30, 45.60], [9.31, 45.60], [9.31, 45.61], [9.30, 45.61], [9.30, 45.60]]],
        ], forceMultiPolygon: true)]);

        $dati = $this->proposte();

        $this->assertSame(10.0, (float) $dati['raggio_m'], 'il raggio e\' l\'altezza dell\'albero');
        $this->assertSame('altezza', $dati['raggio_origine']);
        $this->assertFalse($dati['senza_posizione']);

        // Le aree: prima quella funzionale che contiene l'albero, poi l'altra che lo contiene; mai la prevista o la lontana
        $this->assertSame(['Parco giochi di via Verdi', 'Giardino della scuola'], array_column($dati['aree'], 'nome'));
        $this->assertSame(['contiene', 'contiene'], array_column($dati['aree'], 'relazione'));
        $this->assertSame('Parco giochi di via Verdi', $dati['aree'][0]['etichetta']);

        // Gli elementi: prima chi contiene l'albero, poi per distanza
        $this->assertSame([$areaGioco, $vialetto, $panchina, $gioco], array_column($dati['elementi'], 'id'));
        $this->assertSame(['AG-01 · Area gioco', 'VIA-01 · Percorso/sentiero pavimentato', 'PAN-01 · Panchina in legno', 'GIO-01 · Gioco singolo'],
            array_column($dati['elementi'], 'etichetta'));
        $this->assertTrue($dati['elementi'][0]['contiene']);
        $this->assertSame(0.0, (float) $dati['elementi'][0]['distanza_m']);
        $this->assertEqualsWithDelta(3.0, $dati['elementi'][1]['distanza_m'], 0.3);
        $this->assertEqualsWithDelta(4.0, $dati['elementi'][2]['distanza_m'], 0.3);
        $this->assertEqualsWithDelta(8.0, $dati['elementi'][3]['distanza_m'], 0.3);
        $this->assertSame(['area', 'linea', 'punto', 'punto'], array_column($dati['elementi'], 'forma'));
        $this->assertSame('Parco giochi di via Verdi', $dati['elementi'][2]['area_nome']);
        $this->assertSame(0, $dati['altri']);

        // Allargando il raggio entra anche la panchina a 25 m; la vegetazione resta fuori comunque
        $larghe = $this->proposte(['raggio' => 30]);
        $this->assertSame('richiesto', $larghe['raggio_origine']);
        $this->assertSame(30.0, (float) $larghe['raggio_m']);
        $this->assertContains($lontana, array_column($larghe['elementi'], 'id'));
        $this->assertNotContains('ALB-0002', array_column($larghe['elementi'], 'census_code'));
        $this->assertNotContains('PRA-01', array_column($larghe['elementi'], 'census_code'));
        $this->assertNotContains('PAN-DIS', array_column($larghe['elementi'], 'census_code'));
        $this->assertNotContains('LIM-01', array_column($larghe['elementi'], 'census_code'));
        $this->getJson("/api/v1/assets/{$this->albero}/bersagli-proposti?raggio=500")->assertStatus(422);
    }

    public function test_l_area_della_scheda_si_propone_anche_se_non_contiene_l_albero_e_senza_altezza_il_raggio_e_quello_di_serie(): void
    {
        // Un albero assegnato al filare di viale Roma, il cui poligono sta altrove
        $filare = $this->createArea($this->organizzazione, ['name' => 'Viale Roma - filare', 'geom' => Geometry::toEwkb([
            'type' => 'Polygon', 'coordinates' => [[[9.30, 45.60], [9.31, 45.60], [9.31, 45.61], [9.30, 45.61], [9.30, 45.60]]],
        ], forceMultiPolygon: true)]);
        $this->albero = $this->elemento('P103108', 'ALB-0010', $this->punto(1, 1), area: $filare);

        $dati = $this->proposte();

        $this->assertSame(BersagliProposti::RAGGIO_PREDEFINITO, (float) $dati['raggio_m']);
        $this->assertSame('predefinito', $dati['raggio_origine']);
        $this->assertNull($dati['altezza_m']);
        $this->assertSame(['Parco giochi di via Verdi', 'Viale Roma - filare'], array_column($dati['aree'], 'nome'));
        $this->assertSame(['contiene', 'assegnata'], array_column($dati['aree'], 'relazione'));
        // L'albero di partenza (ALB-0001) non si propone: e' vegetazione
        $this->assertSame([], $dati['elementi']);
    }

    public function test_un_albero_giovane_ha_comunque_un_raggio_minimo_e_un_tetto_di_elementi_dichiarato(): void
    {
        Tree::query()->where('asset_id', $this->albero)->update(['height_m' => 2.5]);
        for ($i = 0; $i < BersagliProposti::LIMITE + 2; $i++) {
            $this->elemento('P224000', sprintf('CES-%02d', $i), $this->punto(1 + $i * 0.1, 1), 'cestino');
        }

        $dati = $this->proposte();

        $this->assertSame(BersagliProposti::RAGGIO_MINIMO, (float) $dati['raggio_m']);
        $this->assertCount(BersagliProposti::LIMITE, $dati['elementi']);
        $this->assertSame(2, $dati['altri']);
    }

    public function test_la_proposta_segue_i_permessi_e_il_perimetro_di_zona(): void
    {
        $this->elemento('P219012', 'PAN-01', $this->punto(4, 0), 'panchina in legno - punto');

        // Chi non vede il censimento non ha proposte
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $cliente = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        $cliente->assignRole('cliente');
        $this->actingAsTenantUser($cliente);
        $this->getJson("/api/v1/assets/{$this->albero}/bersagli-proposti")->assertForbidden();

        // Un tecnico di un'altra zona non trova nemmeno l'albero
        $altroParco = $this->createArea($this->organizzazione, ['name' => 'Parco di un altro committente']);
        $zona = Zone::create(['tenant_id' => $this->organizzazione->id, 'name' => 'Zona Sud']);
        $zona->clients()->sync([$altroParco->locality->site->client_id]);
        $zona->users()->sync([$this->utente->id]);
        PerimetroZone::azzera();
        $this->actingAsTenantUser($this->utente->fresh());
        $this->getJson("/api/v1/assets/{$this->albero}/bersagli-proposti")->assertNotFound();
    }

    public function test_il_nome_del_tipo_perde_la_coda_della_geometria(): void
    {
        $this->assertSame('Panchina in legno', BersagliProposti::nomeTipo('panchina in legno - punto'));
        $this->assertSame('Panchina generica', BersagliProposti::nomeTipo('panchina generica - linea'));
        $this->assertSame('Panchina areale', BersagliProposti::nomeTipo('panchina areale'));
        $this->assertSame('Gioco complesso', BersagliProposti::nomeTipo('gioco complesso'));
        $this->assertSame('', BersagliProposti::nomeTipo(null));
    }
}
