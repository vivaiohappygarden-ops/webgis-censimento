<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\Pdf\PdfRenderer;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * Pagina pubblica dell'elemento: attivazione per singolo elemento (mai
 * automatica) e cartellino stampabile col QR. La pagina mostra solo dati
 * divulgativi, niente note o dati interni.
 */
class PublicPageController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.update', only: ['enable', 'disable']),
            new Middleware('can:assets.view', only: ['tag']),
        ];
    }

    public function enable(string $id): JsonResponse
    {
        $asset = Asset::query()->findOrFail($id);

        if ($asset->public_token === null) {
            $asset->public_token = bin2hex(random_bytes(16));
            $asset->save();
            Audit::log('asset.public_page_enabled', $asset);
        }

        return response()->json(['data' => [
            'public_token' => $asset->public_token,
            'url' => route('public.tree', $asset->public_token),
        ]]);
    }

    public function disable(string $id): Response
    {
        $asset = Asset::query()->findOrFail($id);

        if ($asset->public_token !== null) {
            $asset->public_token = null;
            $asset->save();
            Audit::log('asset.public_page_disabled', $asset);
        }

        return response()->noContent();
    }

    /** Cartellino A6 con QR, nome e indirizzo della pagina pubblica. */
    public function tag(PdfRenderer $renderer, string $id)
    {
        $asset = Asset::query()->with(['objectType', 'tree'])->findOrFail($id);

        if ($asset->public_token === null) {
            throw ValidationException::withMessages([
                'asset' => 'La pagina pubblica non è attiva per questo elemento: attivala dalla scheda.',
            ]);
        }

        $organization = \App\Models\Organization::query()
            ->where('is_active', true)->find($asset->tenant_id);
        if ($organization === null) {
            throw ValidationException::withMessages([
                'asset' => 'Organizzazione non attiva: la pagina pubblica non è raggiungibile.',
            ]);
        }

        $url = route('public.tree', $asset->public_token);
        $svg = (new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(300),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd,
        )))->writeString($url);

        $pdf = $renderer->render('pdf.tag', [
            'organization' => $organization,
            'asset' => $asset,
            'url' => $url,
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($svg),
        ], 'A6');

        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $asset->census_code ?? substr($asset->id, 0, 8));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"cartellino_{$name}.pdf\"",
        ]);
    }
}
