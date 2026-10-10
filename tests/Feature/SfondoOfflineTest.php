<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Mappe\PmTiles\Direttorio;
use App\Services\Mappe\PmTiles\Estrattore;
use App\Services\Mappe\PmTiles\IdTessera;
use App\Services\Mappe\PmTiles\Intestazione;
use App\Services\Mappe\PmTiles\LettorePmTiles;
use App\Services\Mappe\PmTiles\ScrittorePmTiles;
use App\Services\Mappe\PmTiles\SorgenteFile;
use App\Services\Mappe\PmTiles\Voce;
use App\Services\Mappe\SfondoOffline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Lo sfondo della mappa per l'uso senza rete (10/10/2026): il lettore e lo
 * scrittore PMTiles scritti in PHP, l'estrattore che ritaglia il territorio
 * dal pianeta a intervalli, il comando e le chiamate dell'app di campo.
 * Il pianeta di prova e' stato scritto dal pacchetto Python ufficiale: e' il
 * metro con cui si misura il lettore.
 */
class SfondoOfflineTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private const PIANETA_URL = 'https://pianeta.prova/20261001.pmtiles';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        config(['sfondo.sorgente.fissa' => null, 'sfondo.sorgente.elenco' => 'https://pianeta.prova/builds.json', 'sfondo.sorgente.base' => 'https://pianeta.prova/', 'sfondo.margine_km' => 2.0, 'sfondo.zoom_max' => 15]);
    }

    private function pianeta(): string
    {
        return base_path('tests/Fixtures/sfondo/pianeta-prova.pmtiles');
    }

    /** Il pianeta di prova servito in rete, solo a intervalli (Range), piu' l'elenco delle costruzioni. */
    private function pianetaInRete(): void
    {
        $byte = file_get_contents($this->pianeta());
        Http::fake(function (Request $richiesta) use ($byte) {
            if (str_ends_with($richiesta->url(), 'builds.json')) {
                return Http::response([['key' => '20260930.pmtiles', 'size' => 1], ['key' => '20261001.pmtiles', 'size' => 2], ['key' => 'altro.txt']], 200);
            }
            if ($richiesta->url() === self::PIANETA_URL) {
                $range = $richiesta->header('Range')[0] ?? null;
                if (! $range || ! preg_match('/^bytes=(\d+)-(\d+)$/', $range, $m)) {
                    return Http::response('Serve l\'intestazione Range', 400);
                }
                $inizio = (int) $m[1];
                $fine = min((int) $m[2], strlen($byte) - 1);
                $corpo = substr($byte, $inizio, $fine - $inizio + 1);

                return Http::response($corpo, 206, ['Content-Range' => "bytes $inizio-$fine/".strlen($byte), 'Content-Length' => (string) strlen($corpo)]);
            }

            return Http::response('Indirizzo non previsto nella prova: '.$richiesta->url(), 404);
        });
    }

    public function test_i_numeri_delle_tessere_coincidono_con_la_libreria_ufficiale(): void
    {
        // Valori calcolati con zxyToTileId / tileIdToZxy del pacchetto JavaScript ufficiale
        $casi = [
            [0, 0, 0, 0], [1, 0, 0, 1], [1, 1, 0, 4], [1, 0, 1, 2], [1, 1, 1, 3], [2, 3, 1, 17], [5, 17, 12, 1200],
            [10, 536, 371, 1239492], [12, 2147, 1487, 19831877], [15, 17218, 11624, 1270055971],
            [15, 17230, 11630, 1270055767], [18, 137766, 92993, 81283582316], [20, 551065, 371972, 1300537317070],
        ];
        foreach ($casi as [$z, $x, $y, $id]) {
            $this->assertSame($id, IdTessera::daZxy($z, $x, $y), "$z/$x/$y");
            $this->assertSame([$z, $x, $y], IdTessera::aZxy($id), "id $id");
        }
        mt_srand(7);
        for ($i = 0; $i < 500; $i++) {
            $z = mt_rand(0, 22);
            $x = mt_rand(0, (1 << $z) - 1);
            $y = mt_rand(0, (1 << $z) - 1);
            $this->assertSame([$z, $x, $y], IdTessera::aZxy(IdTessera::daZxy($z, $x, $y)));
        }
        // Milano al 15: la colonna e la riga della tessera che contiene il Comune Demo
        $this->assertSame(17220, IdTessera::colonna(9.19, 15));
        $this->assertSame(11727, IdTessera::riga(45.465, 15));
        $this->assertSame(['x0' => 17220, 'x1' => 17221, 'y0' => 11726, 'y1' => 11728], IdTessera::intervallo([9.19, 45.46, 9.20, 45.47], 15));
    }

    public function test_il_direttorio_si_codifica_e_si_rilegge_uguale(): void
    {
        $voci = [new Voce(5, 0, 10, 1), new Voce(6, 10, 20, 3), new Voce(20, 10, 20, 1), new Voce(21, 100, 7, 0)];
        $riletto = Direttorio::decodifica(Direttorio::codifica($voci));
        $this->assertCount(4, $riletto);
        foreach ($voci as $i => $v) {
            $this->assertSame([$v->tileId, $v->offset, $v->length, $v->runLength], [$riletto[$i]->tileId, $riletto[$i]->offset, $riletto[$i]->length, $riletto[$i]->runLength]);
        }
        $this->assertSame(6, Direttorio::trova($riletto, 8)?->tileId, 'una ripetizione copre le tessere seguenti');
        $this->assertNull(Direttorio::trova($riletto, 9));
        $this->assertSame(21, Direttorio::trova($riletto, 999)?->tileId, 'la foglia copre tutto cio\' che segue');
        $this->assertNull(Direttorio::trova($riletto, 4));
    }

    public function test_il_lettore_legge_l_archivio_scritto_dallo_scrittore_ufficiale(): void
    {
        $lettore = new LettorePmTiles(new SorgenteFile($this->pianeta()));
        $h = $lettore->intestazione();
        $this->assertSame([10, 15], [$h->minZoom, $h->maxZoom]);
        $this->assertSame([552, 476, 417], [$h->addressedTiles, $h->tileEntries, $h->tileContents]);
        $this->assertTrue($h->clustered);
        $this->assertSame(Intestazione::COMPRESSIONE_GZIP, $h->tileCompression);
        $this->assertSame(Intestazione::TIPO_MVT, $h->tileType);
        $this->assertEqualsWithDelta(9.10, $h->minLon, 1e-6);
        $this->assertSame('Pianeta di prova (schema Protomaps)', $lettore->metadati()['name']);

        $tessera = $lettore->tesseraDecompressa(15, 17220, 11727);
        $this->assertNotNull($tessera, 'la tessera del Comune Demo c\'e\'');
        $this->assertSame("\x1a", $tessera[0], 'una tessera MVT comincia con il campo "layer" del protobuf');
        $this->assertStringContainsString('roads', $tessera);
        $this->assertStringContainsString('Comune Demo', $tessera, 'il nome del Comune sta nella tessera che lo contiene');
        $this->assertStringContainsString('buildings', (string) $lettore->tesseraDecompressa(15, 17218, 11727));

        $this->assertNull($lettore->tessera(15, 17000, 11727), 'fuori dal pianeta di prova');
        $this->assertNull($lettore->tessera(16, 34436, 23454), 'oltre lo zoom massimo');

        // Le ripetizioni dello scrittore ufficiale (la corona di tessere identiche)
        $ripetute = array_values(array_filter($lettore->radice(), fn (Voce $v) => $v->runLength > 1));
        $this->assertNotEmpty($ripetute);
        $prima = $ripetute[0];
        $dentro = $lettore->voce($prima->tileId + 1);
        $this->assertSame($prima->offset, $dentro?->offset, 'la seconda tessera della ripetizione punta agli stessi byte');
    }

    public function test_lo_scrittore_produce_archivi_con_foglie_che_il_lettore_rilegge(): void
    {
        // Numeri sparsi e contenuti casuali: il direttorio non si comprime e deve
        // finire nelle foglie, come nel pianeta vero
        mt_srand(42);
        $scrittore = new ScrittorePmTiles;
        $attese = [];
        $base = intdiv((1 << 30) - 1, 3);
        while (count($attese) < 30000) {
            $id = $base + mt_rand(0, (1 << 30) - 1);
            if (isset($attese[$id])) {
                continue;
            }
            $attese[$id] = random_bytes(mt_rand(5, 60));
            $scrittore->aggiungi($id, $attese[$id]);
        }
        $percorso = tempnam(sys_get_temp_dir(), 'pmt');
        $h = $scrittore->finalizza($percorso, ['name' => 'prova'], ['tileCompression' => Intestazione::COMPRESSIONE_NESSUNA, 'riquadro' => [9.1, 45.4, 9.3, 45.5]]);

        $this->assertLessThanOrEqual(ScrittorePmTiles::RADICE_MASSIMA, $h->rootLength, 'la radice sta nei primi 16 KB');
        $this->assertGreaterThan(0, $h->leafLength, 'le voci sono finite nelle foglie');
        $this->assertSame(30000, $h->addressedTiles);
        $this->assertSame(30000, $h->tileEntries);
        $this->assertSame(15, $h->minZoom);

        $lettore = new LettorePmTiles(new SorgenteFile($percorso));
        $this->assertSame('prova', $lettore->metadati()['name']);
        $campione = array_slice(array_keys($attese), 0, 300, true);
        foreach ($campione as $id) {
            [$z, $x, $y] = IdTessera::aZxy($id);
            $this->assertSame($attese[$id], $lettore->tessera($z, $x, $y), "tessera $id");
        }
        $assente = $base + 7;
        while (isset($attese[$assente])) {
            $assente++;
        }
        [$z, $x, $y] = IdTessera::aZxy($assente);
        $this->assertNull($lettore->tessera($z, $x, $y));
        unlink($percorso);
    }

    public function test_lo_scrittore_unisce_i_contenuti_uguali_in_ripetizioni(): void
    {
        $scrittore = new ScrittorePmTiles;
        $id = IdTessera::daZxy(12, 2147, 1487);
        foreach ([2, 0, 1] as $k) {
            $scrittore->aggiungi($id + $k, gzencode('uguale'));
        }
        $scrittore->aggiungi($id + 10, gzencode('uguale'));
        $scrittore->aggiungi($id + 11, gzencode('diversa'));
        $percorso = tempnam(sys_get_temp_dir(), 'pmt');
        $h = $scrittore->finalizza($percorso);

        $this->assertSame([5, 3, 2], [$h->addressedTiles, $h->tileEntries, $h->tileContents], 'tre tessere consecutive identiche sono una voce sola');
        $this->assertSame(0, $h->leafLength);
        $lettore = new LettorePmTiles(new SorgenteFile($percorso));
        foreach ([0, 1, 2, 10] as $k) {
            [$z, $x, $y] = IdTessera::aZxy($id + $k);
            $this->assertSame('uguale', $lettore->tesseraDecompressa($z, $x, $y));
        }
        [$z, $x, $y] = IdTessera::aZxy($id + 11);
        $this->assertSame('diversa', $lettore->tesseraDecompressa($z, $x, $y));
        [$z, $x, $y] = IdTessera::aZxy($id + 3);
        $this->assertNull($lettore->tessera($z, $x, $y));
        unlink($percorso);
    }

    public function test_l_estrattore_ritaglia_il_territorio_dal_pianeta_in_rete(): void
    {
        $this->pianetaInRete();
        [$organizzazione] = $this->createTenantUser();
        $this->createArea($organizzazione);
        $servizio = app(SfondoOffline::class);

        $riquadro = $servizio->riquadroTerritorio($organizzazione->id);
        $this->assertEqualsWithDelta(9.189 - 2 / (111.32 * cos(deg2rad(45.465))), $riquadro[0], 1e-3);
        $this->assertEqualsWithDelta(45.4665 + 2 / 111.32, $riquadro[3], 1e-3);

        $righe = [];
        $stato = $servizio->prepara($organizzazione, null, function (string $riga) use (&$righe) {
            $righe[] = $riga;
        });

        $this->assertTrue($stato['disponibile']);
        $this->assertSame('20261001.pmtiles', $stato['versione'], 'l\'ultima costruzione dell\'elenco, non un file di testo');
        $this->assertSame([10, 15], [$stato['zoom_min'], $stato['zoom_max']]);
        $this->assertSame(IdTessera::conta($riquadro, 10, 15), $stato['tessere'], 'tutte le tessere del riquadro, a ogni zoom');
        $this->assertStringContainsString('Sfondo pronto', end($righe));

        $percorso = $servizio->percorsoFile($organizzazione->id);
        $this->assertFileExists($percorso);
        $this->assertSame(filesize($percorso), $stato['byte']);
        $estratto = new LettorePmTiles(new SorgenteFile($percorso));
        $pianeta = new LettorePmTiles(new SorgenteFile($this->pianeta()));
        $this->assertSame($pianeta->tessera(15, 17218, 11727), $estratto->tessera(15, 17218, 11727), 'i byte della tessera sono quelli del pianeta');
        $this->assertSame($pianeta->tessera(12, 2152, 1465), $estratto->tessera(12, 2152, 1465));
        $this->assertNull($estratto->tessera(15, 17150, 11727), 'una tessera lontana non entra');
        $this->assertSame('Pianeta di prova (schema Protomaps) (estratto)', $estratto->metadati()['name']);

        // Dal pianeta si leggono solo intervalli, mai il file intero, e mai blocchi enormi
        Http::assertSent(fn (Request $r) => $r->url() === self::PIANETA_URL && preg_match('/^bytes=(\d+)-(\d+)$/', $r->header('Range')[0] ?? '', $m) && ($m[2] - $m[1] + 1) <= Estrattore::BLOCCO_MASSIMO);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::PIANETA_URL && ! $r->hasHeader('Range'));
        $letture = collect(Http::recorded())->filter(fn ($coppia) => $coppia[0]->url() === self::PIANETA_URL)->count();
        $this->assertLessThan(30, $letture, 'le tessere vicine si leggono con poche richieste grandi');

        // Il secondo giro con lo stato recente non si rifa', con --forza si'
        $this->assertTrue($servizio->eRecente($organizzazione->id));
        $this->artisan('sfondo:prepara', ['--organizzazione' => $organizzazione->slug])
            ->expectsOutputToContain('Sfondo recente')->assertExitCode(0);
        $this->artisan('sfondo:prepara', ['--organizzazione' => $organizzazione->slug, '--forza' => true])
            ->expectsOutputToContain('Sfondo pronto')->assertExitCode(0);
    }

    public function test_il_comando_prepara_tutte_le_organizzazioni_e_salta_chi_non_ha_territorio(): void
    {
        $this->pianetaInRete();
        [$conTerritorio] = $this->createTenantUser();
        $this->createArea($conTerritorio);
        $vuota = Organization::create(['name' => 'Studio senza dati', 'slug' => 'vuota', 'is_active' => true]);
        $sospesa = Organization::create(['name' => 'Studio sospeso', 'slug' => 'sospesa', 'is_active' => false]);

        $this->artisan('sfondo:prepara', ['--tutte' => true])
            ->expectsOutputToContain('Sfondo pronto')
            ->expectsOutputToContain('Studio senza dati')
            ->expectsOutputToContain('niente territorio')
            ->doesntExpectOutputToContain('Studio sospeso')
            ->assertExitCode(0);
        $servizio = app(SfondoOffline::class);
        $this->assertNotNull($servizio->stato($conTerritorio->id));
        $this->assertNull($servizio->stato($vuota->id));
        $this->assertNull($servizio->stato($sospesa->id));

        $this->artisan('sfondo:prepara')->assertExitCode(2);
        $this->artisan('sfondo:prepara', ['--organizzazione' => 'inesistente'])->assertExitCode(1);
    }

    public function test_da_un_file_sul_disco_si_estrae_senza_rete(): void
    {
        Http::fake();
        [$organizzazione] = $this->createTenantUser();
        $this->createArea($organizzazione);
        $this->artisan('sfondo:prepara', ['--organizzazione' => $organizzazione->slug, '--sorgente' => $this->pianeta(), '--zoom-max' => 13])
            ->expectsOutputToContain('Sfondo pronto')->assertExitCode(0);
        $stato = app(SfondoOffline::class)->stato($organizzazione->id);
        $this->assertSame(13, $stato['zoom_max']);
        $this->assertStringStartsWith('pianeta-prova.pmtiles@', $stato['versione']);
        Http::assertNothingSent();
    }

    public function test_le_chiamate_dell_app_di_campo_danno_stato_e_file_a_intervalli(): void
    {
        [$organizzazione, $utente] = $this->createTenantUser();
        $this->createArea($organizzazione);
        $this->actingAsTenantUser($utente);

        $this->getJson('/api/v1/sfondo')->assertOk()->assertJsonPath('data.disponibile', false);
        $this->get('/api/v1/sfondo/territorio.pmtiles')->assertNotFound();

        app(SfondoOffline::class)->prepara($organizzazione, $this->pianeta());
        $stato = $this->getJson('/api/v1/sfondo')->assertOk()
            ->assertJsonPath('data.disponibile', true)
            ->assertJsonPath('data.zoom_max', 15)
            ->json('data');
        $this->assertStringEndsWith('/api/v1/sfondo/territorio.pmtiles', $stato['url']);
        $this->assertGreaterThan(1000, $stato['byte']);

        $intero = $this->get('/api/v1/sfondo/territorio.pmtiles')->assertOk();
        $this->assertSame('application/vnd.pmtiles', $intero->headers->get('Content-Type'));
        $this->assertSame((string) $stato['byte'], $intero->headers->get('Content-Length'));

        $pezzo = $this->get('/api/v1/sfondo/territorio.pmtiles', ['Range' => 'bytes=0-126']);
        $pezzo->assertStatus(206);
        $this->assertSame('127', $pezzo->headers->get('Content-Length'));
        $this->assertSame('bytes 0-126/'.$stato['byte'], $pezzo->headers->get('Content-Range'));
        $this->assertStringStartsWith('PMTiles', $pezzo->streamedContent());

        // Un'altra organizzazione non vede lo sfondo di questa
        [, $altro] = $this->createTenantUser();
        $this->actingAsTenantUser($altro);
        $this->getJson('/api/v1/sfondo')->assertOk()->assertJsonPath('data.disponibile', false);

        // Chi non puo' scaricare i dati di campo non scarica nemmeno lo sfondo
        [, $cliente] = $this->createTenantUser([], 'cliente');
        $this->actingAsTenantUser($cliente);
        $this->getJson('/api/v1/sfondo')->assertForbidden();
    }
}
