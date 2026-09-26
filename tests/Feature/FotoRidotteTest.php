<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Locality;
use App\Models\Photo;
use App\Models\Site;
use App\Models\TreeAssessment;
use App\Models\User;
use App\Services\Photos\ImageDerivative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * La copia d'archivio delle fotografie (dal 26/09/2026).
 *
 * Le foto caricate dal computer arrivavano intere, anche 5-15 MB l'una, e
 * cosi' restavano: dieci volte lo spazio di quelle ridotte dall'app di campo.
 * Ora in archivio entra una copia ridotta (2000 px sul lato lungo, JPEG),
 * raddrizzata secondo l'orientamento EXIF; quelle gia' piccole restano come
 * sono. Peso e impronta registrati sono quelli del file salvato davvero.
 */
class FotoRidotteTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $area = $this->createArea($this->organizzazione);
        // Un tipo con la scheda albero: serve alla prova sulla perizia validata
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);

        $this->assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    /** Un JPEG di GD delle misure date, con un po' di disegno perche' non sia un blocco uniforme. */
    private function jpeg(int $larghezza, int $altezza, int $qualita = 90): string
    {
        $img = imagecreatetruecolor($larghezza, $altezza);
        imagefilledrectangle($img, 0, 0, $larghezza - 1, $altezza - 1, imagecolorallocate($img, 60, 120, 40));
        // Un rettangolo chiaro in alto a sinistra: serve a riconoscere il raddrizzamento
        imagefilledrectangle($img, 0, 0, (int) ($larghezza / 4), (int) ($altezza / 4), imagecolorallocate($img, 240, 240, 240));
        ob_start();
        imagejpeg($img, null, $qualita);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function png(int $larghezza, int $altezza): string
    {
        $img = imagecreatetruecolor($larghezza, $altezza);
        imagefilledrectangle($img, 0, 0, $larghezza - 1, $altezza - 1, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** Inietta nel JPEG un segmento EXIF con il solo orientamento, come farebbe un telefono. */
    private function conOrientamento(string $jpeg, int $orientamento): string
    {
        $tiff = "II*\x00".pack('V', 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, $orientamento)."\x00\x00".pack('V', 0);
        $app1 = "Exif\x00\x00".$tiff;
        $segmento = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

        return substr($jpeg, 0, 2).$segmento.substr($jpeg, 2);
    }

    private function carica(string $nome, string $contenuto): Photo
    {
        $id = $this->postJson("/api/v1/assets/{$this->assetId}/photos", [
            'photo' => UploadedFile::fake()->createWithContent($nome, $contenuto),
        ])->assertCreated()->json('data.id');

        return Photo::query()->findOrFail($id);
    }

    /** @return array{0: int, 1: int, 2: string} larghezza, altezza e mime del file salvato */
    private function misureSalvate(Photo $foto): array
    {
        $info = getimagesizefromstring(Storage::disk('local')->get($foto->s3_key));

        return [$info[0], $info[1], $info['mime']];
    }

    public function test_una_foto_grande_dal_computer_entra_ridotta_e_i_dati_sono_quelli_del_file_salvato(): void
    {
        $foto = $this->carica('dal-computer.jpg', $this->jpeg(4000, 3000));

        $salvata = Storage::disk('local')->get($foto->s3_key);
        $this->assertSame([2000, 1500, 'image/jpeg'], $this->misureSalvate($foto));
        $this->assertStringEndsWith('.jpg', $foto->s3_key);
        $this->assertSame(strlen($salvata), $foto->size_bytes);
        $this->assertSame(hash('sha256', $salvata), $foto->hash_sha256);
        $this->assertSame('image/jpeg', $foto->mime_type);
        $this->assertSame('dal-computer.jpg', $foto->original_filename);
        // La copia ridotta non porta piu' EXIF (GD lascia solo un commento)
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($salvata)) ?: [];
        $this->assertArrayNotHasKey('Orientation', $exif);
        $this->assertStringNotContainsString('EXIF', (string) ($exif['SectionsFound'] ?? ''));
    }

    public function test_una_foto_gia_piccola_resta_come_arriva(): void
    {
        // Come quelle ridotte dall'app di campo (1600 px, 200-500 KB)
        $originale = $this->jpeg(1600, 1200);
        $foto = $this->carica('campo.jpg', $originale);

        $this->assertSame($originale, Storage::disk('local')->get($foto->s3_key));
        $this->assertSame(hash('sha256', $originale), $foto->hash_sha256);
        $this->assertSame(strlen($originale), $foto->size_bytes);
    }

    public function test_una_foto_sdraiata_dal_telefono_viene_raddrizzata(): void
    {
        // 2400 x 1200 salvata sdraiata con orientamento 6: in verticale e' 1200 x 2400,
        // ridotta a 1000 x 2000. Senza il raddrizzamento uscirebbe 2000 x 1000
        $foto = $this->carica('verticale.jpg', $this->conOrientamento($this->jpeg(2400, 1200), 6));

        $this->assertSame([1000, 2000, 'image/jpeg'], $this->misureSalvate($foto));

        // Il rettangolo chiaro era in alto a sinistra: ruotando di 90 gradi in
        // senso orario finisce in alto a destra
        $img = imagecreatefromstring(Storage::disk('local')->get($foto->s3_key));
        $altoDestra = imagecolorsforindex($img, imagecolorat($img, 990, 10));
        $altoSinistra = imagecolorsforindex($img, imagecolorat($img, 10, 10));
        $this->assertGreaterThan(200, $altoDestra['red'], 'l\'angolo chiaro deve stare in alto a destra');
        $this->assertLessThan(120, $altoSinistra['red'], 'in alto a sinistra deve esserci il verde');
    }

    public function test_anche_una_foto_piccola_ma_sdraiata_viene_raddrizzata(): void
    {
        $foto = $this->carica('piccola-verticale.jpg', $this->conOrientamento($this->jpeg(1200, 800), 6));

        $this->assertSame([800, 1200, 'image/jpeg'], $this->misureSalvate($foto));
    }

    public function test_un_png_grande_diventa_jpeg(): void
    {
        $foto = $this->carica('schermata.png', $this->png(3000, 2000));

        $this->assertSame([2000, 1333, 'image/jpeg'], $this->misureSalvate($foto));
        $this->assertSame('image/jpeg', $foto->mime_type);
        $this->assertStringEndsWith('.jpg', $foto->s3_key);
    }

    public function test_la_data_di_scatto_negli_exif_sopravvive_alla_riduzione(): void
    {
        // Il segmento EXIF porta anche DateTimeOriginal (0x9003): si legge
        // prima della riduzione che lo elimina, e finisce in taken_at
        $jpeg = $this->jpeg(2400, 1800);
        $data = "2026:05:14 10:30:00\x00";
        // IFD0 con una sola voce (ExifIFD pointer) e un sotto-IFD con DateTimeOriginal
        $ifd0 = pack('v', 1).pack('vvVV', 0x8769, 4, 1, 26).pack('V', 0);          // 2 + 12 + 4 = 18 byte, inizia a 8 -> exif IFD a 26
        $exifIfd = pack('v', 1).pack('vvVV', 0x9003, 2, strlen($data), 44).pack('V', 0); // inizia a 26, lungo 18 -> dati a 44
        $tiff = "II*\x00".pack('V', 8).$ifd0.$exifIfd.$data;
        $app1 = "Exif\x00\x00".$tiff;
        $conData = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);

        $foto = $this->carica('datata.jpg', $conData);

        $this->assertSame([2000, 1500, 'image/jpeg'], $this->misureSalvate($foto));
        $this->assertSame('2026-05-14 10:30', $foto->taken_at->setTimezone('Europe/Rome')->format('Y-m-d H:i'));
    }

    /** Una foto "di prima": il file intero sul disco e la riga com'era registrata. */
    private function fotoVecchia(string $contenuto, ?string $creata = null): Photo
    {
        $percorso = "photos/{$this->organizzazione->id}/{$this->assetId}/".Str::uuid7().'.jpg';
        Storage::disk('local')->put($percorso, $contenuto);
        $foto = Photo::create([
            'tenant_id' => $this->organizzazione->id,
            'asset_id' => $this->assetId,
            'category' => 'census',
            's3_key' => $percorso,
            'original_filename' => 'intera.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($contenuto),
            'hash_sha256' => hash('sha256', $contenuto),
            'taken_by' => $this->utente->id,
        ]);
        if ($creata !== null) {
            Photo::query()->whereKey($foto->id)->update(['created_at' => $creata]);
        }

        return $foto->fresh();
    }

    /** Un JPEG pesante: rumore a caso non si comprime, e supera la soglia d'archivio pur restando piccolo di lato. */
    private function jpegPesante(): string
    {
        $img = imagecreatetruecolor(1800, 1400);
        mt_srand(7);
        for ($y = 0; $y < 1400; $y += 2) {
            for ($x = 0; $x < 1800; $x += 2) {
                imagefilledrectangle($img, $x, $y, $x + 1, $y + 1, imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        ob_start();
        imagejpeg($img, null, 100);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    public function test_il_comando_riduce_le_foto_gia_in_archivio_prima_in_anteprima_poi_davvero(): void
    {
        $grande = $this->fotoVecchia($this->jpeg(4000, 3000));
        $pesante = $this->fotoVecchia($this->jpegPesante());
        $this->assertGreaterThan(ImageDerivative::BYTE_ARCHIVIO, $pesante->size_bytes);
        $piccola = $this->fotoVecchia($this->jpeg(1600, 1200));
        $vecchioPercorso = $grande->s3_key;

        // Anteprima: niente cambia
        $this->artisan('foto:riduci-archivio')->assertSuccessful()
            ->expectsOutputToContain('verrebbe ridotta')
            ->expectsOutputToContain('Anteprima');
        $this->assertSame($vecchioPercorso, $grande->fresh()->s3_key);
        Storage::disk('local')->assertExists($vecchioPercorso);

        $this->artisan('foto:riduci-archivio', ['--esegui' => true])->assertSuccessful();

        $grande->refresh();
        $this->assertNotSame($vecchioPercorso, $grande->s3_key);
        Storage::disk('local')->assertMissing($vecchioPercorso);
        $salvata = Storage::disk('local')->get($grande->s3_key);
        $this->assertSame([2000, 1500, 'image/jpeg'], $this->misureSalvate($grande));
        $this->assertSame(strlen($salvata), $grande->size_bytes);
        $this->assertSame(hash('sha256', $salvata), $grande->hash_sha256);

        // Quella pesante ma piccola di lato: ricodificata, quindi molto piu' leggera
        $this->assertLessThan($pesante->size_bytes / 2, $pesante->fresh()->size_bytes);
        // Quella gia' buona non si tocca
        $this->assertSame($piccola->s3_key, $piccola->fresh()->s3_key);
    }

    public function test_il_comando_non_tocca_le_foto_negli_atti_di_una_perizia_validata(): void
    {
        $this->patchJson("/api/v1/assets/{$this->assetId}", ['tree' => ['species' => 'Tilia cordata']])->assertOk();
        $valutazione = $this->postJson("/api/v1/assets/{$this->assetId}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => '2026-03-01', 'failure_class' => 'B', 'outcome' => 'monitor',
        ])->assertCreated()->json('data');
        TreeAssessment::query()->whereKey($valutazione['id'])
            ->update(['validated_at' => '2026-04-01 10:00:00', 'validated_by' => $this->utente->id]);

        $negliAtti = $this->fotoVecchia($this->jpeg(4000, 3000), '2026-03-15 09:00:00');
        $dopoLaFirma = $this->fotoVecchia($this->jpeg(4000, 3000), '2026-05-01 09:00:00');

        $this->artisan('foto:riduci-archivio', ['--esegui' => true])->assertSuccessful();

        $this->assertSame($negliAtti->s3_key, $negliAtti->fresh()->s3_key, 'la foto negli atti della perizia validata deve restare intatta');
        $this->assertSame([4000, 3000, 'image/jpeg'], $this->misureSalvate($negliAtti->fresh()));
        $this->assertSame([2000, 1500, 'image/jpeg'], $this->misureSalvate($dopoLaFirma->fresh()));
    }

    public function test_anche_la_foto_di_una_richiesta_dal_portale_esce_dritta(): void
    {
        $cliente = Client::create(['tenant_id' => $this->organizzazione->id, 'name' => 'Comune Foto', 'client_type' => 'public']);
        $sede = Site::create(['tenant_id' => $this->organizzazione->id, 'client_id' => $cliente->id, 'name' => 'Sede']);
        Locality::create(['tenant_id' => $this->organizzazione->id, 'site_id' => $sede->id, 'name' => 'Localita']);
        $portale = User::factory()->create(['tenant_id' => $this->organizzazione->id, 'client_id' => $cliente->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $portale->assignRole('cliente');
        $this->actingAsTenantUser($portale);

        $id = $this->postJson('/api/v1/portal/requests', [
            'description' => 'Ramo pericolante, foto dal telefono.',
            'photos' => [UploadedFile::fake()->createWithContent('ramo.jpg', $this->conOrientamento($this->jpeg(2400, 1200), 6))],
        ])->assertCreated()->json('data.id');

        $foto = Photo::query()->where('subject_type', 'issue')->where('subject_id', $id)->firstOrFail();
        $this->assertSame([1000, 2000, 'image/jpeg'], $this->misureSalvate($foto));
    }
}
