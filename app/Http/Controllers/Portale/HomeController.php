<?php

namespace App\Http\Controllers\Portale;

use App\Http\Controllers\Controller;
use App\Services\Photos\ImageDerivative;
use App\Support\PortalContext;
use Illuminate\Support\Facades\Storage;

/**
 * Home del portale pubblico di un committente.
 *
 * Il committente è già stato riconosciuto dal middleware: qui non si risale
 * mai al tenant dall'utente (non c'è utente) e ogni query filtra sul
 * committente del contesto.
 */
class HomeController extends Controller
{
    public function home(PortalContext $portale)
    {
        return view('portale.home', [
            'portale' => $portale,
        ]);
    }

    /**
     * Stemma del Comune. Viene ricodificato come le foto pubbliche: il file
     * caricato può portare metadati che non ha senso pubblicare.
     */
    public function logo(PortalContext $portale)
    {
        $path = (string) ($portale->client->public_profile['logo_path'] ?? '');
        abort_if($path === '', 404);

        $disk = Storage::disk();
        abort_unless($disk->exists($path), 404);

        $png = ImageDerivative::png($disk->get($path), maxDimension: 320);
        abort_if($png === null, 404);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
