<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mappe\SfondoOffline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Lo sfondo della mappa per l'uso senza rete, come lo vede l'app di campo:
 * lo stato (c'e', quanto pesa, di quando e') e il file, servito a intervalli
 * (Range) perche' MapLibre, quando e' in rete, ne legge solo i pezzi che
 * servono, e il telefono lo scarica intero una volta per averlo offline.
 */
class SfondoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:sync-campo')];
    }

    public function stato(Request $request, SfondoOffline $sfondo): JsonResponse
    {
        $stato = $sfondo->stato($request->user()->tenant_id);
        if ($stato === null) {
            return response()->json(['data' => [
                'disponibile' => false,
                'messaggio' => 'Lo sfondo per l\'uso senza rete non e\' ancora stato preparato per questa organizzazione: si prepara dal server con "php artisan sfondo:prepara".',
            ]]);
        }

        return response()->json(['data' => $stato + ['url' => url('/api/v1/sfondo/'.SfondoOffline::NOME_FILE)]]);
    }

    public function file(Request $request, SfondoOffline $sfondo)
    {
        $tenantId = $request->user()->tenant_id;
        $stato = $sfondo->stato($tenantId);
        abort_if($stato === null, 404, 'Sfondo non preparato per questa organizzazione.');

        // BinaryFileResponse serve da solo gli intervalli (Range) e risponde 206
        return response()->file($sfondo->percorsoFile($tenantId), [
            'Content-Type' => 'application/vnd.pmtiles',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'ETag' => '"'.($stato['impronta'] ?? md5((string) ($stato['generato_il'] ?? ''))).'"',
            'X-Sfondo-Versione' => (string) ($stato['versione'] ?? ''),
        ]);
    }
}
