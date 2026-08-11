<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Photo;
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
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:15360'],
            'category' => ['nullable', 'in:census,reference,before,during,after,organ,defect,issue,other'],
            // UUID generato dal device (PWA offline): rende l'upload replay-safe
            'client_id' => ['nullable', 'uuid'],
        ]);

        if (! empty($data['client_id'])) {
            $existing = Photo::query()->find($data['client_id']);
            if ($existing) {
                // Retry dopo timeout: la foto è già arrivata, si risponde con quella
                return response()->json(['data' => $existing, 'duplicate' => true], 200);
            }
        }

        $file = $data['photo'];
        $path = $file->store("photos/{$asset->tenant_id}/{$asset->id}");

        $photo = new Photo([
            'tenant_id' => $asset->tenant_id,
            'asset_id' => $asset->id,
            'category' => $data['category'] ?? 'census',
            's3_key' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'image/jpeg',
            'size_bytes' => $file->getSize(),
            'hash_sha256' => hash_file('sha256', $file->getRealPath()),
            'taken_at' => now(),
            'geom' => $this->geomFromExif($file->getRealPath()),
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

            return response()->json([
                'data' => Photo::query()->findOrFail($data['client_id']),
                'duplicate' => true,
            ], 200);
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

        Audit::log('photo.deleted', $photo);

        return response()->noContent();
    }

    /** Estrae la posizione GPS dagli EXIF (solo JPEG, best effort). */
    private function geomFromExif(string $realPath): ?string
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        try {
            $exif = @exif_read_data($realPath);
            if (! $exif || empty($exif['GPSLatitude']) || empty($exif['GPSLongitude'])) {
                return null;
            }

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
