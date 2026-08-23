<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Photo;
use App\Services\Photos\ImageDerivative;
use App\Support\Audit;
use App\Support\Geometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['file']),
            new Middleware('can:assets.update', only: ['store', 'destroy']),
        ];
    }

    public function store(Request $request, string $assetId): JsonResponse
    {
        $asset = Asset::findOrFail($assetId);

        $data = $request->validate([
            // Il tetto in pixel e' lo stesso di ImageDerivative: una foto che
            // il programma non riesce a ridurre non si potrebbe mostrare da
            // nessuna parte e sparirebbe in silenzio da perizie, schede e
            // portale. Meglio dirlo subito, al caricamento, che scoprirlo in
            // stampa mesi dopo.
            'photo' => [
                'required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:15360',
                $this->nonOltreIlMassimoDiPixel(...),
            ],
            'category' => ['nullable', 'in:census,reference,before,during,after,organ,defect,issue,other'],
            // UUID generato dal device (PWA offline): rende l'upload replay-safe
            'client_id' => ['nullable', 'uuid'],
            // La compressione sul device elimina gli EXIF: data di scatto e
            // posizione arrivano come campi espliciti
            'taken_at' => ['nullable', 'date'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lon' => ['nullable', 'numeric', 'between:-180,180'],
            // Foto scattata durante un lavoro: resta agganciata a quell'ordine
            // e puo' comparire nella cronologia pubblica dell'elemento
            'work_order_id' => ['nullable', 'uuid'],
        ]);

        if (! empty($data['client_id'])) {
            $existing = Photo::query()->find($data['client_id']);
            if ($existing) {
                if ($existing->asset_id !== $asset->id) {
                    // Stesso id su un elemento diverso: errore del client, non replay
                    abort(422, 'Identificativo foto già usato per un altro elemento.');
                }

                // Retry dopo timeout: la foto è già arrivata, si risponde con quella
                return response()->json(['data' => $existing, 'duplicate' => true], 200);
            }
        }

        // L'ordine deve esistere nell'organizzazione: TenantScope filtra da sé
        $ordine = ! empty($data['work_order_id'])
            ? \App\Models\WorkOrder::query()->findOrFail($data['work_order_id'])
            : null;

        $file = $data['photo'];
        // Gli EXIF si leggono una volta sola: servono sia alla data di scatto
        // sia alla posizione
        $exif = $this->exif($file->getRealPath());
        $path = $file->store("photos/{$asset->tenant_id}/{$asset->id}");

        $photo = new Photo([
            'tenant_id' => $asset->tenant_id,
            'asset_id' => $asset->id,
            'subject_type' => $ordine ? $ordine->getMorphClass() : null,
            'subject_id' => $ordine?->id,
            'category' => $data['category'] ?? 'census',
            's3_key' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'image/jpeg',
            'size_bytes' => $file->getSize(),
            'hash_sha256' => hash_file('sha256', $file->getRealPath()),
            // Normalizzata a UTC: il cast datetime salva il valore senza convertire il fuso.
            // Se il client non la manda si legge dagli EXIF: il momento del
            // caricamento non e' la data dello scatto, e nella perizia finisce
            // stampata sotto la fotografia
            'taken_at' => isset($data['taken_at'])
                ? \Illuminate\Support\Carbon::parse($data['taken_at'])->utc()
                : ($this->dataScattoDagliExif($exif) ?? now()),
            'geom' => $this->geomFromExif($exif)
                ?? (isset($data['lat'], $data['lon'])
                    ? Geometry::toEwkb(['type' => 'Point', 'coordinates' => [(float) $data['lon'], (float) $data['lat']]])
                    : null),
            'taken_by' => $request->user()->id,
        ]);
        if (! empty($data['client_id'])) {
            $photo->id = $data['client_id'];
        }

        try {
            $photo->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Due retry simultanei dello stesso upload: vince il primo, il file
            // appena scritto si elimina e si risponde con la foto già registrata
            Storage::disk()->delete($path);

            $winner = Photo::query()->where('asset_id', $asset->id)->find($data['client_id'] ?? '');
            if (! $winner) {
                // Collisione con un id estraneo (altro tenant o altro elemento)
                abort(422, 'Identificativo foto non utilizzabile.');
            }

            return response()->json(['data' => $winner, 'duplicate' => true], 200);
        }

        Audit::log('photo.uploaded', $photo, ['asset_id' => $asset->id]);

        return response()->json(['data' => $photo->fresh()], 201);
    }

    public function file(string $id): StreamedResponse|Response
    {
        $photo = Photo::findOrFail($id);
        $disk = Storage::disk();

        abort_unless($disk->exists($photo->s3_key), 404);

        return $disk->response($photo->s3_key, $photo->original_filename, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function destroy(string $id): Response
    {
        $photo = Photo::findOrFail($id);
        $photo->delete();

        // La copia pubblica ridimensionata non deve sopravvivere all'originale
        \App\Services\Photos\PublicPhotoCache::dimentica($photo);

        Audit::log('photo.deleted', $photo);

        return response()->noContent();
    }

    /**
     * Rifiuta le immagini troppo grandi per essere ricodificate.
     *
     * Il messaggio dice il numero e il rimedio: chi carica non deve indovinare
     * perche' la sua foto non va bene.
     */
    private function nonOltreIlMassimoDiPixel(string $campo, mixed $valore, \Closure $errore): void
    {
        if (! $valore instanceof \Illuminate\Http\UploadedFile) {
            return;
        }

        $info = @getimagesize($valore->getRealPath());
        if ($info === false) {
            return; // non e' un'immagine: se ne accorge la regola "image"
        }

        $pixel = $info[0] * $info[1];
        if ($pixel <= ImageDerivative::PIXEL_MASSIMI) {
            return;
        }

        $errore(sprintf(
            'La fotografia è troppo grande: %s megapixel (%d x %d), il massimo è %d. '
            .'Riducila prima di caricarla, o scattala a risoluzione più bassa.',
            number_format($pixel / 1_000_000, 1, ',', '.'),
            $info[0], $info[1],
            (int) (ImageDerivative::PIXEL_MASSIMI / 1_000_000),
        ));
    }

    /** Gli EXIF del file, o null se non ce ne sono o non si possono leggere. */
    private function exif(string $realPath): ?array
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        try {
            return @exif_read_data($realPath) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Data di scatto letta dagli EXIF della fotografia.
     *
     * Senza questa, la data della foto era il momento del caricamento: chi
     * rileva in campo e scarica le foto la sera, o il giorno dopo, si trovava
     * nella perizia una data che non e' quella del sopralluogo. L'app di campo
     * manda la data per conto suo (campo taken_at, perche' la compressione sul
     * telefono cancella gli EXIF); qui si copre il caricamento dal computer.
     *
     * Gli EXIF non portano il fuso orario: si leggono come ora italiana, che e'
     * quella in cui si lavora. Una data impossibile (azzerata, preistorica o
     * nel futuro) si scarta e resta la data di caricamento.
     */
    private function dataScattoDagliExif(?array $exif): ?\Illuminate\Support\Carbon
    {
        if ($exif === null) {
            return null;
        }

        try {
            foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $campo) {
                $valore = $exif[$campo] ?? null;
                if (! is_string($valore) || ! preg_match('/^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/', $valore)) {
                    continue;
                }

                $quando = \DateTimeImmutable::createFromFormat(
                    'Y:m:d H:i:s', $valore, new \DateTimeZone('Europe/Rome'),
                );
                if ($quando === false) {
                    continue;
                }

                $data = \Illuminate\Support\Carbon::instance($quando);
                if ($data->year < 1990 || $data->isFuture()) {
                    continue;
                }

                return $data->utc();
            }
        } catch (\Throwable) {
            // Formato senza EXIF (PNG, WebP) o EXIF illeggibili: nessun problema
        }

        return null;
    }

    /** Estrae la posizione GPS dagli EXIF (solo JPEG, best effort). */
    private function geomFromExif(?array $exif): ?string
    {
        if ($exif === null || empty($exif['GPSLatitude']) || empty($exif['GPSLongitude'])) {
            return null;
        }

        try {
            $lat = $this->dmsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
            $lon = $this->dmsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');

            if ($lat === null || $lon === null) {
                return null;
            }

            return Geometry::toEwkb(['type' => 'Point', 'coordinates' => [$lon, $lat]]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function dmsToDecimal(array $dms, string $ref): ?float
    {
        $parts = array_map(function ($v) {
            if (str_contains((string) $v, '/')) {
                [$n, $d] = explode('/', (string) $v, 2);

                return (float) $d === 0.0 ? 0.0 : (float) $n / (float) $d;
            }

            return (float) $v;
        }, array_pad($dms, 3, 0));

        $decimal = $parts[0] + $parts[1] / 60 + $parts[2] / 3600;
        if ($decimal === 0.0) {
            return null;
        }

        return in_array($ref, ['S', 'W'], true) ? -$decimal : $decimal;
    }
}
