<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Inspection;
use App\Models\MarcaTemporale;
use App\Models\Organization;
use App\Models\User;
use App\Services\Pdf\PdfRenderer;
use App\Support\Rfc3161;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\Support\TsaDiProva;
use Tests\TestCase;

/**
 * Marche temporali (RFC 3161) sui documenti chiusi: il programma produce il
 * PDF, ne manda l'impronta alla TSA, conserva PDF e gettone e li rende
 * verificabili. La TSA delle prove e' vera (openssl), non un finto che dice
 * sempre di si'.
 */
class MarcheTemporaliTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private const PDF_FINTO = '%PDF-1.4 (finto, per i test)';

    private $organizzazione;

    private $utente;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->actingAsTenantUser($this->utente);
        $this->app->instance(PdfRenderer::class, new RaccoglitorePdf);
        config(['marche.utente' => null, 'marche.password' => null, 'marche.catena' => null, 'marche.quota_giorno' => 10,
            'marche.url' => 'https://tsa.prova.test/tsr', 'services.osm.tiles_enabled' => false]);
    }

    /** Credenziali della piattaforma (come dal file .env). */
    private function accendi(int $quota = 10): void
    {
        config(['marche.utente' => 'studio-verde', 'marche.password' => 'segreta', 'marche.quota_giorno' => $quota]);
    }

    /**
     * La TSA di prova risponde alle chiamate del programma; tutto il resto
     * della rete e' spento. Si registra una volta sola per prova: le finte
     * successive si accodano e la prima che corrisponde vince.
     */
    private function tsaVera(string $host = 'tsa.prova.test'): void
    {
        if (! TsaDiProva::disponibile()) {
            $this->markTestSkipped('openssl non disponibile: la TSA di prova non si puo\' creare.');
        }
        Http::fake([
            $host.'/*' => fn ($richiesta) => Http::response(TsaDiProva::rispondi($richiesta->body()), 200, ['Content-Type' => 'application/timestamp-reply']),
            '*' => Http::response('', 404),
        ]);
    }

    /** @return array{0:string,1:string} id dell'elemento e della valutazione */
    private function periziaValidata(): array
    {
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $asset = $this->postJson('/api/v1/assets', ['area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry()])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$asset}", ['tree' => ['species' => 'Tilia cordata']])->assertOk();
        $valutazione = $this->postJson("/api/v1/assets/{$asset}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => '2026-03-10', 'failure_class' => 'B', 'next_check_due' => '2027-03-10',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/assessments/{$valutazione}/valida")->assertOk();

        return [$asset, $valutazione];
    }

    private function ispezioneChiusa(): string
    {
        $modello = $this->postJson('/api/v1/inspection-templates', ['name' => 'Controllo area giochi', 'code' => 'EN1176-001', 'target' => 'area', 'standard_ref' => 'UNI EN 1176-7:2020'])
            ->assertCreated()->json('data.id');
        $voci = $this->putJson("/api/v1/inspection-templates/{$modello}/items", ['items' => [['question' => 'Superfici antitrauma integre']]])->assertOk()->json('data.items');

        return $this->postJson('/api/v1/inspections', ['template_id' => $modello, 'area_id' => $this->area->id, 'answers' => [$voci[0]['id'] => ['value' => 'ok']]])
            ->assertOk()->json('data.id');
    }

    private function utenteCon(array $permessi, string $nomeRuolo): User
    {
        $this->postJson('/api/v1/roles', ['nome' => $nomeRuolo, 'permessi' => $permessi])->assertCreated();
        $utente = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $utente->assignRole($nomeRuolo);

        return $utente->fresh();
    }

    // ---------------------------------------------------------------------------

    public function test_senza_credenziali_le_marche_sono_spente_e_la_pagina_lo_dice(): void
    {
        $stato = $this->getJson('/api/v1/documenti/marche')->assertOk()->json('stato');
        $this->assertFalse($stato['attiva']);
        $this->assertNull($stato['origine']);
        $this->assertFalse($stato['piattaforma_configurata']);

        [, $valutazione] = $this->periziaValidata();
        $this->postJson('/api/v1/documenti/marche', ['tipo' => 'perizia', 'id' => $valutazione])
            ->assertUnprocessable()->assertJsonPath('errors.marca.0', fn ($m) => str_contains($m, 'non sono ancora attive'));
        $this->assertSame(0, MarcaTemporale::query()->count());
        Http::assertNothingSent();
    }

    public function test_la_richiesta_e_la_risposta_rfc3161_si_scrivono_e_si_leggono_davvero(): void
    {
        // La risposta registrata di una TSA di prova: valori noti
        $risposta = Rfc3161::risposta(file_get_contents(base_path('tests/Fixtures/marche/risposta-di-prova.tsr')));
        $this->assertSame(0, $risposta['stato']);
        $this->assertSame('concessa', $risposta['stato_testo']);
        $this->assertSame(hash_file('sha256', base_path('tests/Fixtures/marche/documento-di-prova.txt')), $risposta['tst']['impronta']);
        $this->assertSame(Rfc3161::OID_SHA256, $risposta['tst']['algoritmo']);
        $this->assertSame('1.2.3.4.1', $risposta['tst']['policy']);
        $this->assertSame('2', $risposta['tst']['seriale']);
        $this->assertSame('2026-09-28T10:39:02+00:00', $risposta['tst']['generato_il']->toIso8601String());
        $this->assertSame('544790aaca4f19fd', $risposta['tst']['nonce']);
        $this->assertSame('TSA di prova (WebGIS test)', $risposta['tst']['tsa']);
        $this->assertCount(2, $risposta['certificati']);
        $this->assertSame('TSA di prova', Rfc3161::firmatario($risposta['certificati'])['nome']);

        // Uno stato di rifiuto con motivo e codice di errore
        $rifiuto = Rfc3161::risposta(Rfc3161::sequenza(Rfc3161::sequenza(
            Rfc3161::intero("\x02").Rfc3161::sequenza(Rfc3161::utf8('credito esaurito')).Rfc3161::bit([25]),
        )));
        $this->assertSame(['rifiutata', 'credito esaurito', 'errore interno della TSA', null], [$rifiuto['stato_testo'], $rifiuto['motivo'], $rifiuto['fallimento'], $rifiuto['token']]);

        // La richiesta che scrive il programma la legge openssl
        if (! TsaDiProva::disponibile()) {
            return;
        }
        $file = tempnam(sys_get_temp_dir(), 'tsq');
        file_put_contents($file, Rfc3161::richiesta(hash('sha256', 'documento', true), hex2bin('0badc0ffee'), '1.2.3.4.1'));
        $processo = new Process(['openssl', 'ts', '-query', '-in', $file, '-text']);
        $processo->run();
        @unlink($file);
        $testo = $processo->getOutput();
        $this->assertStringContainsString('Hash Algorithm: sha256', $testo);
        $this->assertStringContainsString('Nonce: 0x0BADC0FFEE', $testo);
        $this->assertStringContainsString('Certificate required: yes', $testo);
        $this->assertMatchesRegularExpression('/Policy OID: (1\.2\.3\.4\.1|tsa_policy1)/', $testo);
    }

    public function test_la_marca_su_una_perizia_validata_conserva_pdf_e_gettone_e_si_verifica(): void
    {
        $this->accendi();
        $this->tsaVera();
        [$asset, $valutazione] = $this->periziaValidata();

        $marca = $this->postJson('/api/v1/documenti/marche', ['tipo' => 'perizia', 'id' => $valutazione])
            ->assertCreated()->json('data');

        $this->assertSame('perizia', $marca['tipo']);
        $this->assertSame($valutazione, $marca['soggetto_id']);
        $this->assertStringStartsWith('Perizia ', $marca['titolo']);
        $this->assertSame(hash('sha256', self::PDF_FINTO), $marca['sha256']);
        $this->assertSame(strlen(self::PDF_FINTO), $marca['dimensione']);
        $this->assertSame('TSA di prova (WebGIS test)', $marca['tsa']);
        $this->assertSame('1.2.3.4.1', $marca['policy']);
        $this->assertSame('tsa.prova.test', $marca['servizio']);
        $this->assertNotEmpty($marca['seriale']);
        $this->assertSame($this->utente->name, $marca['richiesta_da']);
        $this->assertEqualsWithDelta(now()->timestamp, strtotime($marca['generato_il']), 120);

        // Alla TSA e' partita una richiesta RFC 3161 con le credenziali
        Http::assertSent(function ($richiesta) {
            $der = Rfc3161::elemento($richiesta->body());

            return $richiesta->url() === 'https://tsa.prova.test/tsr'
                && $richiesta->hasHeader('Content-Type', 'application/timestamp-query')
                && $richiesta->hasHeader('Authorization', 'Basic '.base64_encode('studio-verde:segreta'))
                && $der['tag'] === 0x30;
        });

        // PDF e gettone conservati; il PDF scaricato e' proprio quello marcato
        $riga = MarcaTemporale::query()->findOrFail($marca['id']);
        Storage::disk('local')->assertExists($riga->percorso_pdf);
        Storage::disk('local')->assertExists($riga->percorso_marca);
        $this->assertSame(self::PDF_FINTO, Storage::disk('local')->get($riga->percorso_pdf));
        $this->get($marca['pdf'])->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="'.pathinfo($marca['nome_file'], PATHINFO_FILENAME).'_marcato.pdf"');
        $this->assertSame(self::PDF_FINTO, $this->get($marca['pdf'])->getContent());
        $gettone = $this->get($marca['tsr'])->assertOk()->assertHeader('Content-Type', 'application/timestamp-reply');
        $this->assertSame(0, Rfc3161::risposta($gettone->getContent())['stato']);

        // Registro
        $this->assertDatabaseHas('audit_logs', ['action' => 'marca.applicata', 'subject_id' => $marca['id'], 'user_id' => $this->utente->id]);

        // Verifica: senza catena la firma resta da controllare fuori, ma impronta e gettone tornano
        $verifica = $this->getJson($marca['verifica'])->assertOk()->json('data');
        $this->assertTrue($verifica['file_presente']);
        $this->assertTrue($verifica['impronta_coincide']);
        $this->assertTrue($verifica['marca_coerente']);
        $this->assertSame('non_controllata', $verifica['firma']);
        $this->assertStringContainsString('MARCHE_CATENA', $verifica['firma_dettaglio']);
        $this->assertTrue($verifica['valida']);
        $this->assertSame('TSA di prova', $verifica['firmatario']['nome']);

        // Con la catena della TSA sul server, openssl verifica anche la firma
        config(['marche.catena' => TsaDiProva::catena()]);
        $verifica = $this->getJson($marca['verifica'])->assertOk()->json('data');
        $this->assertSame('verificata', $verifica['firma']);
        $this->assertTrue($this->getJson('/api/v1/documenti/marche')->json('stato.verifica_firma'));

        // Se qualcuno tocca il PDF conservato, la verifica lo dice
        Storage::disk('local')->put($riga->percorso_pdf, self::PDF_FINTO.' manomesso');
        $verifica = $this->getJson($marca['verifica'])->assertOk()->json('data');
        $this->assertFalse($verifica['impronta_coincide']);
        $this->assertFalse($verifica['marca_coerente']);
        $this->assertFalse($verifica['valida']);

        // Nell'elenco dei documenti la perizia porta la sua marca e la scorciatoia la trova
        $documenti = $this->getJson('/api/v1/documenti')->assertOk()->json();
        $perizia = collect($documenti['data'])->firstWhere('tipo', 'perizia');
        $this->assertTrue($perizia['marcabile']);
        $this->assertTrue($perizia['marcato']);
        $this->assertSame($marca['id'], $perizia['marca']['id']);
        $this->assertSame(1, $documenti['conteggi']['marcati']);
        $this->assertCount(1, $this->getJson('/api/v1/documenti?stato=marcati')->json('data'));

        // L'elenco delle marche e lo stato
        $elenco = $this->getJson('/api/v1/documenti/marche')->assertOk()->json();
        $this->assertSame([$marca['id']], collect($elenco['data'])->pluck('id')->all());
        $this->assertSame(['attiva' => true, 'origine' => 'piattaforma', 'servizio' => 'tsa.prova.test', 'quota_giorno' => 10, 'usate_oggi' => 1],
            collect($elenco['stato'])->only(['attiva', 'origine', 'servizio', 'quota_giorno', 'usate_oggi'])->all());
        $this->assertSame('st********de', $elenco['stato']['utente']);
    }

    public function test_non_si_marcano_perizie_non_validate_ne_ispezioni_aperte(): void
    {
        $this->accendi();
        $this->tsaVera();
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $asset = $this->postJson('/api/v1/assets', ['area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry()])->json('data.id');
        $valutazione = $this->postJson("/api/v1/assets/{$asset}/assessments", ['assessment_type' => 'vta_visual', 'assessed_on' => '2026-03-10', 'failure_class' => 'B'])->json('data.id');

        $this->postJson('/api/v1/documenti/marche', ['tipo' => 'perizia', 'id' => $valutazione])
            ->assertUnprocessable()->assertJsonPath('errors.marca.0', fn ($m) => str_contains($m, 'solo una perizia validata'));

        $ispezione = $this->ispezioneChiusa();
        Inspection::query()->whereKey($ispezione)->update(['completed_at' => null]);
        $this->postJson('/api/v1/documenti/marche', ['tipo' => 'verbale', 'id' => $ispezione])
            ->assertUnprocessable()->assertJsonPath('errors.marca.0', fn ($m) => str_contains($m, 'ancora aperta'));

        // Chiusa, si marca; e nell'elenco il verbale porta la marca
        Inspection::query()->whereKey($ispezione)->update(['completed_at' => now()]);
        $marca = $this->postJson('/api/v1/documenti/marche', ['tipo' => 'verbale', 'id' => $ispezione])->assertCreated()->json('data');
        $this->assertStringStartsWith('Verbale di ispezione · Controllo area giochi', $marca['titolo']);
        $verbale = collect($this->getJson('/api/v1/documenti')->json('data'))->firstWhere('tipo', 'verbale');
        $this->assertSame($marca['id'], $verbale['marca']['id']);

        Http::assertSentCount(1);
        $this->assertSame(1, MarcaTemporale::query()->count());
    }

    public function test_la_quota_giornaliera_ferma_la_marca_in_piu(): void
    {
        $this->accendi(quota: 1);
        $this->tsaVera();
        [, $valutazione] = $this->periziaValidata();

        $this->postJson('/api/v1/documenti/marche', ['tipo' => 'perizia', 'id' => $valutazione])->assertCreated();
        $this->postJson('/api/v1/documenti/marche', ['tipo' => 'perizia', 'id' => $valutazione])
            ->assertUnprocessable()->assertJsonPath('errors.marca.0', fn ($m) => str_contains($m, 'Per oggi le marche sono finite (1 al giorno'));
        $this->assertSame(1, MarcaTemporale::query()->count());

        // La quota e' dell'account: un'altra organizzazione con le stesse credenziali della piattaforma la condivide
        [, $altro] = $this->createTenantUser();
        $this->actingAsTenantUser($altro);
        $this->assertSame(1, $this->getJson('/api/v1/documenti/marche')->json('stato.usate_oggi'));
        $this->assertSame([], $this->getJson('/api/v1/documenti/marche')->json('data'));
    }

    public function test_una_risposta_rifiutata_o_incoerente_non_lascia_niente(): void
    {
        $this->accendi();
        if (! TsaDiProva::disponibile()) {
            $this->markTestSkipped('openssl non disponibile.');
        }
        [, $valutazione] = $this->periziaValidata();
        $applica = fn () => $this->postJson('/api/v1/documenti/marche', ['tipo' => 'perizia', 'id' => $valutazione])->assertUnprocessable()->json('errors.marca.0');

        // Le risposte della TSA, una per tentativo: un rifiuto dichiarato, una
        // marca per un altro documento, credenziali respinte, una pagina HTML
        $rifiuto = Rfc3161::sequenza(Rfc3161::sequenza(Rfc3161::intero("\x02").Rfc3161::sequenza(Rfc3161::utf8('credito esaurito')).Rfc3161::bit([25])));
        $altra = TsaDiProva::rispondi(Rfc3161::richiesta(hash('sha256', 'un altro documento', true), random_bytes(8)));
        Http::fakeSequence('tsa.prova.test/*')
            ->push($rifiuto, 200, ['Content-Type' => 'application/timestamp-reply'])
            ->push($altra, 200, ['Content-Type' => 'application/timestamp-reply'])
            ->push('', 401)
            ->push('<html>errore</html>', 200);

        $messaggio = $applica();
        $this->assertStringContainsString('"rifiutata"', $messaggio);
        $this->assertStringContainsString('credito esaurito', $messaggio);
        $this->assertStringContainsString('errore interno della TSA', $messaggio);
        $this->assertStringContainsString('impronta diversa', $applica());
        $this->assertStringContainsString('rifiutato le credenziali (errore 401)', $applica());
        $this->assertStringContainsString('non si legge', $applica());

        $this->assertSame(0, MarcaTemporale::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, AuditLog::query()->where('action', 'marca.applicata')->count());
    }

    public function test_il_registro_fitosanitari_marcato_diventa_un_documento(): void
    {
        $this->accendi();
        $this->tsaVera();

        $this->postJson('/api/v1/documenti/marche', ['tipo' => 'registro_fitosanitari'])
            ->assertUnprocessable()->assertJsonPath('errors.marca.0', fn ($m) => str_contains($m, "l'anno"));

        $marca = $this->postJson('/api/v1/documenti/marche', ['tipo' => 'registro_fitosanitari', 'parametri' => ['anno' => 2026]])
            ->assertCreated()->json('data');
        $this->assertSame('Registro dei trattamenti fitosanitari 2026', $marca['titolo']);
        $this->assertSame(['year' => 2026], $marca['parametri']);
        $this->assertNull($marca['soggetto_id']);
        $this->assertSame('registro_fitosanitari_2026.pdf', $marca['nome_file']);

        $documenti = $this->getJson('/api/v1/documenti')->assertOk()->json();
        $riga = collect($documenti['data'])->firstWhere('tipo', 'marca');
        $this->assertSame('Registro dei trattamenti fitosanitari 2026', $riga['titolo']);
        $this->assertSame('Marcata', $riga['stato']);
        $this->assertSame($marca['pdf'], $riga['pdf']);
        $this->assertSame($marca['sha256'], $riga['impronta']);
        $this->assertSame($this->utente->name, $riga['utente']);
        $this->assertSame(1, $documenti['conteggi']['marca']);

        // Bilancio arboreo con committente: titolo con il nome, parametri con il periodo
        $cliente = Client::withoutGlobalScopes()->where('tenant_id', $this->organizzazione->id)->firstOrFail();
        $bilancio = $this->postJson('/api/v1/documenti/marche', ['tipo' => 'bilancio_arboreo', 'parametri' => ['anno' => 2026, 'client_id' => $cliente->id]])
            ->assertCreated()->json('data');
        $this->assertSame('Bilancio arboreo 2026 · Cliente Test', $bilancio['titolo']);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-12-31', 'client_id' => $cliente->id, 'anno' => 2026], $bilancio['parametri']);
        $this->assertSame('Cliente Test', collect($this->getJson('/api/v1/documenti?tipo=marca')->json('data'))->firstWhere('id', $bilancio['id'])['committente']);

        // Chi vede solo il censimento non marca i registri dei lavori, ne' li vede
        $this->actingAsTenantUser($this->utenteCon(['assets.view'], 'Solo censimento'));
        $this->postJson('/api/v1/documenti/marche', ['tipo' => 'registro_fitosanitari', 'parametri' => ['anno' => 2026]])->assertForbidden();
        $this->assertSame(['bilancio_arboreo'], collect($this->getJson('/api/v1/documenti/marche')->assertOk()->json('data'))->pluck('tipo')->all());
        $this->getJson($marca['verifica'])->assertNotFound();
        $this->get($marca['pdf'])->assertNotFound();
    }

    public function test_le_credenziali_dell_organizzazione_vincono_su_quelle_della_piattaforma(): void
    {
        $this->accendi();
        $this->tsaVera('tsa.esempio.it');

        // Solo https, a meno che non sia il proprio computer
        $this->putJson('/api/v1/documenti/marche/configurazione', ['url' => 'http://tsa.esempio.it/tsr', 'utente' => 'comune', 'password' => 'pw'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->putJson('/api/v1/documenti/marche/configurazione', ['url' => 'https://tsa.esempio.it/tsr', 'utente' => 'comune'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/documenti/marche/configurazione', ['url' => 'https://tsa.esempio.it/tsr', 'utente' => 'comune', 'password' => 'pw', 'policy' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('policy');

        $configurazione = $this->putJson('/api/v1/documenti/marche/configurazione', [
            'url' => 'https://tsa.esempio.it/tsr', 'utente' => 'comune-verde', 'password' => 'pw-del-comune', 'policy' => '1.3.76.36.1.1.1', 'quota_giorno' => 3,
        ])->assertOk()->json('data');
        $this->assertSame(['url' => 'https://tsa.esempio.it/tsr', 'utente' => 'comune-verde', 'ha_password' => true, 'policy' => '1.3.76.36.1.1.1', 'quota_giorno' => 3],
            collect($configurazione)->only(['url', 'utente', 'ha_password', 'policy', 'quota_giorno'])->all());
        $this->assertSame('organizzazione', $configurazione['stato']['origine']);
        $this->assertSame('tsa.esempio.it', $configurazione['stato']['servizio']);
        $this->assertSame(3, $configurazione['stato']['quota_giorno']);
        $this->assertArrayNotHasKey('password', $configurazione);
        $this->assertArrayNotHasKey('password_cifrata', $configurazione);

        // Sul database la password e' cifrata, non in chiaro
        $salvate = Organization::query()->findOrFail($this->organizzazione->id)->settings['marche'];
        $this->assertArrayNotHasKey('password', $salvate);
        $this->assertStringNotContainsString('pw-del-comune', json_encode($salvate));
        $this->assertDatabaseHas('audit_logs', ['action' => 'marche.configurazione', 'subject_id' => $this->organizzazione->id]);

        // La marca parte verso il servizio dell'organizzazione con le sue credenziali e la sua
        // politica: quella indicata la TSA di prova non la conosce, e lo dice per esteso
        [, $valutazione] = $this->periziaValidata();
        $messaggio = $this->postJson('/api/v1/documenti/marche', ['tipo' => 'perizia', 'id' => $valutazione])->assertUnprocessable()->json('errors.marca.0');
        $this->assertStringContainsString('"rifiutata"', $messaggio);
        $this->assertStringContainsString('politica di marcatura non accettata', $messaggio);
        Http::assertSent(fn ($r) => $r->url() === 'https://tsa.esempio.it/tsr' && $r->hasHeader('Authorization', 'Basic '.base64_encode('comune-verde:pw-del-comune')));

        // Con una politica che la TSA accetta, la marca la porta scritta
        $this->putJson('/api/v1/documenti/marche/configurazione', ['url' => 'https://tsa.esempio.it/tsr', 'utente' => 'comune-verde', 'policy' => '1.2.3.4.5', 'quota_giorno' => 3])->assertOk();
        $marca = $this->postJson('/api/v1/documenti/marche', ['tipo' => 'perizia', 'id' => $valutazione])->assertCreated()->json('data');
        $this->assertSame(['tsa.esempio.it', '1.2.3.4.5'], [$marca['servizio'], $marca['policy']]);

        // Salvare senza password tiene quella di prima
        $this->putJson('/api/v1/documenti/marche/configurazione', ['url' => 'https://tsa.esempio.it/tsr', 'utente' => 'comune-verde', 'quota_giorno' => 5])
            ->assertOk()->assertJsonPath('data.ha_password', true)->assertJsonPath('data.stato.quota_giorno', 5);

        // Chi non gestisce gli utenti non tocca ne' legge le credenziali
        $this->actingAsTenantUser($this->utenteCon(['assets.view', 'works.view'], 'Tecnico senza utenti'));
        $this->getJson('/api/v1/documenti/marche/configurazione')->assertForbidden();
        $this->putJson('/api/v1/documenti/marche/configurazione', ['url' => 'https://x.it', 'utente' => 'a', 'password' => 'b'])->assertForbidden();
        $this->assertSame('organizzazione', $this->getJson('/api/v1/documenti/marche')->assertOk()->json('stato.origine'));

        // Tolte le proprie, si torna a quelle della piattaforma
        $this->actingAsTenantUser($this->utente);
        $this->deleteJson('/api/v1/documenti/marche/configurazione')->assertOk()->assertJsonPath('data.ha_password', false)->assertJsonPath('data.stato.origine', 'piattaforma');
    }
}
