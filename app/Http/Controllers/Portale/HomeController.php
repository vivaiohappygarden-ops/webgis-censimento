<?php

namespace App\Http\Controllers\Portale;

use App\Http\Controllers\Controller;
use App\Services\Photos\ImageDerivative;
use App\Services\Portale\PortalSearch;
use App\Services\Portale\PortalStats;
use App\Support\PortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Home del portale pubblico di un committente e ricerca per etichetta.
 *
 * Il committente è già stato riconosciuto dal middleware: qui non si risale
 * mai al tenant dall'utente (non c'è utente) e ogni query passa da
 * PortalQuery, che porta con sé le regole di pubblicabilità.
 */
class HomeController extends Controller
{
    public function home(PortalContext $portale)
    {
        return view('portale.home', [
            'portale' => $portale,
            'statistiche' => PortalStats::per($portale->client),
            'cercato' => null,
            'nonTrovato' => false,
        ]);
    }

    /** Ricerca per numero di etichetta: se trova, porta dritto alla scheda. */
    public function cerca(Request $request, PortalContext $portale)
    {
        $cercato = (string) $request->query('etichetta', '');
        $asset = $cercato === '' ? null : PortalSearch::trova($portale->client, $cercato);

        if ($asset !== null) {
            return redirect($portale->url('/elemento/'.rawurlencode(PortalSearch::riferimento($asset))));
        }

        return view('portale.home', [
            'portale' => $portale,
            'statistiche' => PortalStats::per($portale->client),
            'cercato' => $cercato,
            'nonTrovato' => $cercato !== '',
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
