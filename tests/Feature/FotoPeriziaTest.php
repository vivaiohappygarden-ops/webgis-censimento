<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\TreeAssessment;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\TestCase;

/**
 * Documentazione fotografica della perizia.
 *
 * Le fotografie sparivano in silenzio per tre motivi diversi: un tetto fisso
 * di quattro, l'esclusione di quelle "successive al sopralluogo" (dove la data
 * dello scatto era in realtà il momento del caricamento) e una soglia di
 * sicurezza sulle immagini che una normale foto da telefono superava.
 */
class FotoPeriziaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private RaccoglitorePdf $stampe;

    private string $assetId;

    private const SOPRALLUOGO = '2026-05-10';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organizzazione, $utente] = $this->createTenantUser();
        $area = $this->createArea($this->organizzazione);
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($utente);

        $this->stampe = new RaccoglitorePdf;
        $this->app->instance(PdfRenderer::class, $this->stampe);

        $this->assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $tipo->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    /** @return string id della fotografia */
    private function carica(string $nome, array $extra = [], int $w = 400, int $h = 300): string
    {
        return $this->post("/api/v1/assets/{$this->assetId}/photos", [
            'photo' => UploadedFile::fake()->image($nome, $w, $h),
            'category' => 'census',
            ...$extra,
        ])->assertCreated()->json('data.id');
    }

    private function creaPerizia(): string
    {
        return $this->postJson("/api/v1/assets/{$this->assetId}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => self::SOPRALLUOGO,
            'failure_class' => 'B',
            'outcome' => 'monitor',
        ])->assertCreated()->json('data.id');
    }

    private function stampa(): string
    {
        $perizia = $this->creaPerizia();
        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();

        return $this->stampe->html['pdf.perizia'];
    }

    private function quanteFoto(string $html): int
    {
        return substr_count($html, 'data:image/jpeg;base64,');
    }

    public function test_tutte_le_fotografie_finiscono_nella_perizia(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->carica("foto{$i}.jpg");
        }

        $html = $this->stampa();

        // Prima ne uscivano quattro, punto
        $this->assertSame(9, $this->quanteFoto($html));
        $this->assertStringContainsString('Foto 9', $html);
    }

    public function test_una_foto_caricata_dopo_il_sopralluogo_non_sparisce_ed_e_dichiarata(): void
    {
        $primaId = $this->carica('durante.jpg');
        Photo::withoutGlobalScopes()->where('id', $primaId)
            ->update(['taken_at' => Carbon::parse(self::SOPRALLUOGO.' 09:00:00')]);

        // Caricata il giorno dopo: e' il caso di chi scarica le foto la sera
        $dopoId = $this->carica('dopo.jpg');
        Photo::withoutGlobalScopes()->where('id', $dopoId)
            ->update(['taken_at' => Carbon::parse('2026-05-12 18:00:00')]);

        $html = $this->stampa();

        $this->assertSame(2, $this->quanteFoto($html));
        $this->assertStringContainsString('ripresa dopo il sopralluogo, il 12/05/2026', $html);
        $this->assertStringContainsString('scattata il 10/05/2026', $html);
    }

    public function test_una_foto_da_telefono_a_piena_risoluzione_compare(): void
    {
        // 4032 x 3024 = 12,19 megapixel: la soglia precedente era 12 milioni
        // di pixel e la scartava senza dirlo
        $this->carica('telefono.jpg', w: 4032, h: 3024);

        $this->assertSame(1, $this->quanteFoto($this->stampa()));
    }

    public function test_oltre_il_tetto_si_tengono_le_piu_vicine_al_sopralluogo_e_il_documento_lo_dice(): void
    {
        // 30 fotografie: 24 entrano, e sono quelle intorno al sopralluogo
        for ($i = 0; $i < 30; $i++) {
            $id = $this->carica("foto{$i}.jpg");
            Photo::withoutGlobalScopes()->where('id', $id)->update([
                'taken_at' => Carbon::parse(self::SOPRALLUOGO)->subDays(15)->addDays($i),
            ]);
        }

        $html = $this->stampa();

        $this->assertSame(24, $this->quanteFoto($html));
        $this->assertStringContainsString('risultano 30 fotografie', $html);
        $this->assertStringContainsString('ne sono allegate 24', $html);
    }

    public function test_una_foto_illeggibile_non_fa_sparire_le_altre_e_viene_dichiarata(): void
    {
        $this->carica('buona.jpg');
        $rotta = $this->carica('rotta.jpg');

        // Il file sparisce dal disco: succede con un ripristino incompleto.
        // Sparisce anche la copia ridotta, che ora si prepara al caricamento
        $foto = Photo::withoutGlobalScopes()->findOrFail($rotta);
        Storage::disk('local')->delete($foto->s3_key);
        \App\Services\Photos\PublicPhotoCache::dimentica($foto);

        $html = $this->stampa();

        $this->assertSame(1, $this->quanteFoto($html));
        $this->assertStringContainsString('Una fotografia non è stata allegata', $html);
    }

    public function test_la_didascalia_porta_la_categoria(): void
    {
        $this->carica('difetto.jpg', ['category' => 'defect']);

        $this->assertStringContainsString('- difetto -', $this->stampa());
    }

    public function test_senza_fotografie_il_documento_lo_dice_e_non_aggiunge_note(): void
    {
        $html = $this->stampa();

        $this->assertStringContainsString('Nessuna fotografia allegata', $html);
        $this->assertStringNotContainsString('non sono state allegate', $html);
    }

    public function test_la_data_di_scatto_arriva_dagli_exif_della_fotografia(): void
    {
        $file = $this->fileConExif('2026:05:10 09:15:00');

        $id = $this->post("/api/v1/assets/{$this->assetId}/photos", [
            'photo' => $file, 'category' => 'census',
        ])->assertCreated()->json('data.id');

        $foto = Photo::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame(
            '10/05/2026 09:15',
            $foto->taken_at->setTimezone('Europe/Rome')->format('d/m/Y H:i'),
        );
    }

    public function test_la_data_mandata_dal_dispositivo_vince_sugli_exif(): void
    {
        // L'app di campo comprime la foto (gli EXIF spariscono) e manda la data
        // per conto suo: quella deve restare quella buona
        $id = $this->post("/api/v1/assets/{$this->assetId}/photos", [
            'photo' => $this->fileConExif('2020:01:01 12:00:00'),
            'category' => 'census',
            'taken_at' => '2026-05-10T07:00:00Z',
        ])->assertCreated()->json('data.id');

        $foto = Photo::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('2026-05-10', $foto->taken_at->utc()->toDateString());
    }

    public function test_una_data_exif_impossibile_non_viene_usata(): void
    {
        $id = $this->post("/api/v1/assets/{$this->assetId}/photos", [
            'photo' => $this->fileConExif('0000:00:00 00:00:00'), 'category' => 'census',
        ])->assertCreated()->json('data.id');

        $foto = Photo::withoutGlobalScopes()->findOrFail($id);
        $this->assertTrue($foto->taken_at->isToday(), 'Doveva restare la data di caricamento.');
    }

    public function test_una_perizia_validata_non_cambia_se_si_aggiungono_foto_dopo(): void
    {
        $this->carica('rilievo1.jpg');
        $this->carica('rilievo2.jpg');

        $perizia = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$perizia}/valida")->assertOk();

        // Il documento chiuso, com'era al momento della firma
        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();
        $this->assertSame(2, $this->quanteFoto($this->stampe->html['pdf.perizia']));

        // Una fotografia aggiunta il giorno dopo non entra in un atto gia'
        // firmato, ma il documento dichiara che c'e'
        $this->travel(1)->days();
        $this->carica('tardiva.jpg');

        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();
        $html = $this->stampe->html['pdf.perizia'];

        $this->assertSame(2, $this->quanteFoto($html));
        $this->assertStringContainsString(
            'Una fotografia caricata dopo la validazione non fa parte di questo atto.',
            $html,
        );
    }

    public function test_finche_e_bozza_le_foto_nuove_entrano_subito(): void
    {
        $this->carica('prima.jpg');
        $perizia = $this->creaPerizia();

        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();
        $this->assertSame(1, $this->quanteFoto($this->stampe->html['pdf.perizia']));

        $this->carica('seconda.jpg');
        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();

        $this->assertSame(2, $this->quanteFoto($this->stampe->html['pdf.perizia']));
    }

    public function test_una_foto_cancellata_dopo_la_validazione_resta_nell_atto(): void
    {
        $this->carica('rilievo1.jpg');
        $daCancellare = $this->carica('rilievo2.jpg');

        $perizia = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$perizia}/valida")->assertOk();

        // Cancellare una fotografia non deve cambiare un documento gia' firmato
        $this->travel(1)->days();
        $this->deleteJson("/api/v1/photos/{$daCancellare}")->assertNoContent();

        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();

        $this->assertSame(2, $this->quanteFoto($this->stampe->html['pdf.perizia']));
    }

    public function test_una_foto_cancellata_prima_della_validazione_non_torna(): void
    {
        $this->carica('buona.jpg');
        $sbagliata = $this->carica('sbagliata.jpg');
        $this->deleteJson("/api/v1/photos/{$sbagliata}")->assertNoContent();

        $perizia = $this->creaPerizia();
        $this->postJson("/api/v1/assessments/{$perizia}/valida")->assertOk();
        $this->get("/api/v1/assessments/{$perizia}/perizia-pdf")->assertOk();

        $this->assertSame(1, $this->quanteFoto($this->stampe->html['pdf.perizia']));
    }

    public function test_una_foto_troppo_grande_viene_rifiutata_al_caricamento_con_un_motivo(): void
    {
        // Oltre le soglie: senza il rifiuto verrebbe salvata e poi sparirebbe
        // in silenzio da ogni stampa e dal portale
        $risposta = $this->post("/api/v1/assets/{$this->assetId}/photos", [
            'photo' => UploadedFile::fake()->image('enorme.jpg', 8000, 6000),
            'category' => 'census',
        ]);

        $risposta->assertUnprocessable();
        $this->assertStringContainsString('troppo grande', $risposta->json('message'));
        $this->assertStringContainsString('48,0 megapixel', $risposta->json('message'));
    }

    /** Una JPEG vera con dentro un blocco EXIF che porta la data indicata. */
    private function fileConExif(string $quando): UploadedFile
    {
        $img = imagecreatetruecolor(120, 90);
        imagefilledrectangle($img, 0, 0, 120, 90, imagecolorallocate($img, 20, 120, 40));
        ob_start();
        imagejpeg($img, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        // Un IFD0 con la sola voce DateTime (0x0132), come lo scrive una macchina
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1)
            .pack('v', 0x0132).pack('v', 2).pack('V', 20).pack('V', 26)
            .pack('V', 0)
            .$quando."\0";
        $app1 = "Exif\0\0".$tiff;
        $conExif = substr($jpeg, 0, 2)
            ."\xFF\xE1".pack('n', strlen($app1) + 2).$app1
            .substr($jpeg, 2);

        $percorso = tempnam(sys_get_temp_dir(), 'foto').'.jpg';
        file_put_contents($percorso, $conExif);

        return new UploadedFile($percorso, 'scatto.jpg', 'image/jpeg', null, true);
    }
}
