<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PeriziaTest extends TestCase
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

    /**
     * Le regole di Http::fake si accumulano e vince la prima: si registrano
     * una volta sola per test, all'inizio.
     */
    private function fakeTiles(?string $png = null): void
    {
        Http::fake([
            'tile.openstreetmap.org/*' => $png !== null
                ? Http::response($png, 200)
                : Http::response('', 500),
        ]);
    }

    private function createTreeAsset(): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->treeType->id,
            'census_code' => 'ALB-0042',
            'geometry' => $this->pointGeometry(10.5421, 45.4712),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => [
                'genus' => 'Pinus', 'species' => 'Pinus pinea', 'common_name' => 'Pino domestico',
                'height_m' => 14.5, 'dbh_cm' => 62, 'is_monumental' => true,
            ],
        ])->assertOk();

        return $id;
    }

    /**
     * Il contenuto si verifica sul documento composto: dompdf codifica i
     * testi nel PDF, quindi cercarli nel binario darebbe falsi negativi.
     */
    private function renderedHtml(string $assessmentId): string
    {
        $spy = new class extends PdfRenderer
        {
            public string $html = '';

            public function render(string $view, array $data, string $paper = 'A4'): string
            {
                $this->html = view($view, $data)->render();

                return parent::render($view, $data, $paper);
            }
        };
        $this->instance(PdfRenderer::class, $spy);

        $this->get("/api/v1/assessments/{$assessmentId}/perizia-pdf")->assertOk();

        return $spy->html;
    }

    private function makeAssessment(string $asset, array $payload = []): array
    {
        return $this->postJson("/api/v1/assets/{$asset}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now('Europe/Rome')->toDateString(),
            'failure_class' => 'B',
            ...$payload,
        ])->assertCreated()->json('data');
    }

    public function test_perizia_contains_the_extended_survey(): void
    {
        $this->fakeTiles();
        $asset = $this->createTreeAsset();

        $this->putJson('/api/v1/perizia/settings', [
            'nome' => 'Andrea Rossi',
            'titolo' => 'Perito agrario',
            'iscrizione' => 'Collegio dei Periti Agrari n. 123',
            'recapiti' => 'via Roma 1 - tel 000',
        ])->assertOk()->assertJsonPath('data.nome', 'Andrea Rossi');

        $assessment = $this->makeAssessment($asset, [
            'failure_class' => 'C',
            'outcome' => 'prescriptions',
            'prescriptions' => 'Potatura di riduzione della branca sud.',
            'targets' => ['area giochi', 'vialetto pedonale'],
            'survey' => [
                'contesto' => [
                    'ambito' => 'parco urbano', 'sito_radicazione' => 'tappeto erboso',
                    'disposizione' => 'esemplare isolato', 'accessibilita' => 'libera',
                ],
                'interferenze' => 'Linea elettrica aerea a 6 m',
                'giudizio' => [
                    'fase_fisiologica' => 'maturita', 'stato_vegetativo' => 'discreto',
                    'sintetico' => 'esemplare da monitorare', 'patologie_quarantena' => '',
                ],
                'difetti' => [
                    'radici' => 'Radici affioranti sul lato nord',
                    'fusto' => 'Cavita aperta a 1,2 m',
                    'chioma' => 'Disseccamenti diffusi in periferia',
                    // Chiave non prevista dalla scheda: va scartata
                    'inventata' => 'da scartare',
                ],
                'integrazione_vta' => 'Consigliata tomografia sonica',
                'priorita_intervento' => 'entro 30 giorni',
            ],
        ]);
        $this->assertArrayNotHasKey('inventata', $assessment['survey']['difetti']);

        $html = $this->renderedHtml($assessment['id']);
        foreach ([
            'Andrea Rossi', 'Perito agrario', 'Collegio dei Periti Agrari n. 123',
            'ALB-0042', 'Pinus pinea', 'Pino domestico', '14.5', '62',
            'parco urbano', 'tappeto erboso', 'Linea elettrica aerea',
            'area giochi; vialetto pedonale', 'maturita', 'esemplare da monitorare',
            'Accertamento non eseguito', 'Radici affioranti sul lato nord', 'Cavita aperta a 1,2 m',
            'Consigliata tomografia sonica', 'entro 30 giorni',
            'Potatura di riduzione della branca sud.', 'Albero monumentale',
            '45.471200, 10.542100',
        ] as $probe) {
            $this->assertStringContainsString($probe, $html, "manca dalla perizia: {$probe}");
        }
        $this->assertStringNotContainsString('da scartare', $html);
        // Senza conclusioni scritte a mano, il testo si compone dalla classe
        $this->assertStringContainsString('classe C (propensione al cedimento moderata)', $html);
        // Senza mappa raggiungibile il documento lo dichiara
        $this->assertStringContainsString('Estratto cartografico non disponibile', $html);
        // Numero di protocollo assegnato alla prima stampa e stampato in testa
        $numero = \App\Models\TreeAssessment::query()->findOrFail($assessment['id'])->report_number;
        $this->assertSame('PER-'.now('Europe/Rome')->year.'-0001', $numero);
        $this->assertStringContainsString($numero, $html);
        // Esito e tipo di valutazione: non piu' sottintesi
        $this->assertStringContainsString('interventi prescritti', $html);
        $this->assertStringContainsString('Analisi visiva (VTA)', $html);
    }

    public function test_written_conclusions_replace_the_automatic_text(): void
    {
        $this->fakeTiles();
        $asset = $this->createTreeAsset();
        $assessment = $this->makeAssessment($asset, [
            'survey' => ['conclusioni' => 'Conclusioni redatte dal tecnico incaricato.'],
        ]);

        $html = $this->renderedHtml($assessment['id']);
        $this->assertStringContainsString('Conclusioni redatte dal tecnico', $html);
        $this->assertStringNotContainsString('Sulla base dell', $html);
    }

    public function test_perizia_works_without_survey_or_professional_data(): void
    {
        $this->fakeTiles();
        $this->organization->forceFill(['name' => 'Vivaio Prova'])->save();
        $asset = $this->createTreeAsset();
        $assessment = $this->makeAssessment($asset, [
            'failure_class' => 'A',
            'defects' => ['piccola ferita al colletto'],
        ]);

        $html = $this->renderedHtml($assessment['id']);
        // Senza dati del professionista restano nome dell'organizzazione e
        // titolo generico; i difetti del modulo breve non si perdono
        $this->assertStringContainsString('Vivaio Prova', $html);
        $this->assertStringContainsString('Tecnico incaricato', $html);
        $this->assertStringContainsString('piccola ferita al colletto', $html);
        // Silenzio del tecnico: mai trasformato in un accertamento negativo
        $this->assertStringContainsString('Non rilevate in sede di sopralluogo', $html);
        $this->assertStringContainsString('Nessuna fotografia allegata', $html);

        // Il documento vero e proprio esce comunque
        $pdf = $this->get("/api/v1/assessments/{$assessment['id']}/perizia-pdf")->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertGreaterThan(5000, strlen($pdf->getContent()));
    }

    public function test_photos_and_instrumental_analyses_are_included(): void
    {
        $this->fakeTiles();
        Storage::fake('local');
        $asset = $this->createTreeAsset();

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk()->put('photos/test/a.png', $png);
        Photo::create([
            'tenant_id' => $this->organization->id,
            'asset_id' => $asset,
            's3_key' => 'photos/test/a.png',
            'original_filename' => 'fusto.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($png),
            'category' => 'defect',
        ]);

        $assessment = $this->makeAssessment($asset);
        $this->postJson("/api/v1/assessments/{$assessment['id']}/instrumental-analyses", [
            'instrument_type' => 'sonic_tomograph',
            'instrument_model' => 'Arbotom',
            'measured_at' => now('Europe/Rome')->toDateString(),
            'measurement_height_cm' => 130,
            'measures' => ['residuo sano' => '62%', 'cavita' => '18%'],
            'notes' => 'Sezione con perdita di massa contenuta.',
        ])->assertCreated();

        $html = $this->renderedHtml($assessment['id']);
        // La foto e' ricodificata in JPEG: mai l'originale, mai i suoi metadati
        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringNotContainsString(base64_encode($png), $html);
        $this->assertStringContainsString('scattata il', $html);
        $this->assertStringNotContainsString('Nessuna fotografia allegata', $html);
        foreach (['Tomografo sonico', 'Arbotom', '130 cm', 'residuo sano', '62%',
            'Sezione con perdita di massa'] as $probe) {
            $this->assertStringContainsString($probe, $html, "manca: {$probe}");
        }

        // La foto entra davvero nel PDF composto
        $this->assertStringContainsString(
            '/Image',
            $this->get("/api/v1/assessments/{$assessment['id']}/perizia-pdf")->getContent(),
        );
    }

    public function test_map_snippet_is_embedded_when_tiles_are_reachable(): void
    {
        $tile = imagecreatetruecolor(256, 256);
        ob_start();
        imagepng($tile);
        $png = (string) ob_get_clean();
        imagedestroy($tile);
        $this->fakeTiles($png);

        $asset = $this->createTreeAsset();
        $assessment = $this->makeAssessment($asset);

        $html = $this->renderedHtml($assessment['id']);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('OpenStreetMap', $html);
        $this->assertStringNotContainsString('Estratto cartografico non disponibile', $html);
        // Mosaico 3x3 attorno al punto, scaricato in una volta sola
        Http::assertSentCount(9);

        // I riquadri restano in cache: la seconda perizia sullo stesso
        // giardino non richiama OpenStreetMap
        $secondo = $this->makeAssessment($asset, ['failure_class' => 'A']);
        $this->renderedHtml($secondo['id']);
        Http::assertSentCount(9);
    }

    public function test_perizia_is_refused_without_the_failure_class(): void
    {
        $this->fakeTiles();
        $asset = $this->createTreeAsset();
        $assessment = $this->makeAssessment($asset, ['failure_class' => null]);

        $this->getJson("/api/v1/assessments/{$assessment['id']}/perizia-pdf")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('failure_class');

        $this->assertNull(
            \App\Models\TreeAssessment::query()->findOrFail($assessment['id'])->report_number,
        );
    }

    public function test_report_number_and_issue_date_do_not_change_between_downloads(): void
    {
        $this->fakeTiles();
        $asset = $this->createTreeAsset();
        $assessment = $this->makeAssessment($asset);

        $this->get("/api/v1/assessments/{$assessment['id']}/perizia-pdf")->assertOk();
        $first = \App\Models\TreeAssessment::query()->findOrFail($assessment['id']);
        $numero = $first->report_number;
        $emessa = $first->report_issued_at;

        // Il giorno dopo la stessa perizia si ristampa identica
        $this->travel(1)->days();
        $html = $this->renderedHtml($assessment['id']);
        $second = \App\Models\TreeAssessment::query()->findOrFail($assessment['id']);

        $this->assertSame($numero, $second->report_number);
        $this->assertTrue($emessa->equalTo($second->report_issued_at));
        $this->assertStringContainsString($emessa->setTimezone('Europe/Rome')->format('d/m/Y'), $html);
        $this->travelBack();
    }

    public function test_photo_metadata_never_reaches_the_signed_document(): void
    {
        $this->fakeTiles();
        Storage::fake('local');
        $asset = $this->createTreeAsset();

        // JPEG con un segmento EXIF riconoscibile, come quello di un telefono
        $canvas = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($canvas);
        $jpeg = (string) ob_get_clean();
        imagedestroy($canvas);
        $exif = "\xFF\xE1".pack('n', 6 + 22)."Exif\x00\x00".'TELEFONO-SERIALE-9971';
        $conExif = substr($jpeg, 0, 2).$exif.substr($jpeg, 2);

        Storage::disk()->put('photos/test/con-exif.jpg', $conExif);
        Photo::create([
            'tenant_id' => $this->organization->id,
            'asset_id' => $asset,
            's3_key' => 'photos/test/con-exif.jpg',
            'original_filename' => 'fusto.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($conExif),
            'category' => 'defect',
        ]);

        $assessment = $this->makeAssessment($asset);
        $html = $this->renderedHtml($assessment['id']);

        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringNotContainsString('TELEFONO-SERIALE-9971', $html);
        $this->assertStringNotContainsString(base64_encode($conExif), $html);
        $this->assertStringNotContainsString(
            'TELEFONO-SERIALE-9971',
            $this->get("/api/v1/assessments/{$assessment['id']}/perizia-pdf")->getContent(),
        );
    }

    public function test_photos_taken_after_the_survey_are_not_presented_as_evidence(): void
    {
        $this->fakeTiles();
        Storage::fake('local');
        $asset = $this->createTreeAsset();

        $canvas = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($canvas);
        $jpeg = (string) ob_get_clean();
        imagedestroy($canvas);

        foreach ([['vecchia', '-40 days'], ['recente', '-2 days'], ['successiva', '+3 days']] as [$nome, $quando]) {
            Storage::disk()->put("photos/test/{$nome}.jpg", $jpeg);
            Photo::create([
                'tenant_id' => $this->organization->id,
                'asset_id' => $asset,
                's3_key' => "photos/test/{$nome}.jpg",
                'mime_type' => 'image/jpeg',
                'category' => 'census',
                'taken_at' => now('Europe/Rome')->modify($quando),
            ]);
        }

        $assessment = $this->makeAssessment($asset);
        $this->renderedHtml($assessment['id']);

        // Due sole foto: quella scattata dopo il sopralluogo resta fuori
        $recenti = (new \ReflectionClass(\App\Http\Controllers\Api\V1\PeriziaController::class))
            ->getMethod('photos');
        $recenti->setAccessible(true);
        $foto = $recenti->invoke(
            app(\App\Http\Controllers\Api\V1\PeriziaController::class),
            $asset,
            \App\Models\TreeAssessment::query()->findOrFail($assessment['id']),
        );

        $this->assertCount(2, $foto);
        // La più recente entro il sopralluogo viene per prima, con la sua data
        $this->assertSame(now('Europe/Rome')->modify('-2 days')->format('d/m/Y'), $foto[0]['scattata']);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $this->fakeTiles();
        $asset = $this->createTreeAsset();
        $assessment = $this->makeAssessment($asset);

        // L'operatore legge la perizia ma non tocca l'intestazione
        $operatore = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operatore->assignRole('operatore');
        $this->actingAsTenantUser($operatore);
        $this->get("/api/v1/assessments/{$assessment['id']}/perizia-pdf")->assertOk();
        $this->putJson('/api/v1/perizia/settings', ['nome' => 'X'])->assertForbidden();

        // Un altro tenant non arriva alla perizia altrui
        [, $foreign] = $this->createTenantUser();
        $this->actingAsTenantUser($foreign);
        $this->get("/api/v1/assessments/{$assessment['id']}/perizia-pdf")->assertNotFound();

        // Il cliente non ha accesso al censimento
        $client = \App\Models\User::factory()->create([
            'tenant_id' => $this->organization->id, 'user_type' => 'client_portal',
        ]);
        $client->assignRole('cliente');
        $this->actingAsTenantUser($client);
        $this->get("/api/v1/assessments/{$assessment['id']}/perizia-pdf")->assertForbidden();
    }
}
