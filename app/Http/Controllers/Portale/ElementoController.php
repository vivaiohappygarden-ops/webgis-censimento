<?php

namespace App\Http\Controllers\Portale;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Photo;
use App\Services\Photos\ImageDerivative;
use App\Services\Portale\PortalSearch;
use App\Services\Portale\PortalState;
use App\Support\PortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Scheda pubblica del singolo elemento sul portale del committente.
 *
 * Mostra solo dati divulgativi: mai le note interne, mai le foto dei difetti
 * o delle segnalazioni. Vale la stessa regola della pagina del QR.
 *
 * Il codice si legge dalla rotta e non dalla firma del metodo: quando un
 * parametro risolto dal contenitore (PortalContext) precede quelli della
 * rotta, Laravel li assegna per posizione e il codice arriverebbe sbagliato.
 */
class ElementoController extends Controller
{
    /** Solo le categorie divulgative: mai foto di difetti o segnalazioni. */
    private const FOTO_PUBBLICHE = ['census', 'reference'];

    public function mostra(Request $request, PortalContext $portale)
    {
        $asset = PortalSearch::perRiferimento($portale->client, (string) $request->route('codice'));
        abort_if($asset === null, 404);

        // Non tutti gli elementi sono punti: aiuole e siepi sono aree e
        // linee. ST_PointOnSurface dà un punto rappresentativo che cade
        // sempre dentro la geometria, anche quando il baricentro ne uscirebbe
        $dati = DB::selectOne(
            'SELECT ST_Y(ST_PointOnSurface(geom::geometry)) AS lat,
                    ST_X(ST_PointOnSurface(geom::geometry)) AS lon, '
            .PortalState::sql().' AS stato FROM assets WHERE id = ?',
            [$asset->id],
        );

        $asset->load([
            'objectType' => fn ($q) => $q->withoutGlobalScopes(),
            'tree' => fn ($q) => $q->withoutGlobalScopes(),
            'area' => fn ($q) => $q->withoutGlobalScopes()->whereNull('deleted_at'),
            'area.locality' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        return view('portale.elemento', [
            'portale' => $portale,
            'asset' => $asset,
            'stato' => $dati->stato,
            'lat' => (float) $dati->lat,
            'lon' => (float) $dati->lon,
            'hasFoto' => $this->fotoPubblica($asset) !== null,
        ]);
    }

    /**
     * La foto viene SEMPRE ricodificata: il file originale può contenere
     * coordinate GPS e altri metadati che la pagina, per scelta, non pubblica.
     */
    public function foto(Request $request, PortalContext $portale)
    {
        $asset = PortalSearch::perRiferimento($portale->client, (string) $request->route('codice'));
        abort_if($asset === null, 404);

        $foto = $this->fotoPubblica($asset);
        abort_if($foto === null, 404);

        $jpeg = ImageDerivative::jpeg(Storage::disk()->get($foto->s3_key), maxDimension: 1200, quality: 78);
        abort_if($jpeg === null, 404);

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto.jpg"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function fotoPubblica(Asset $asset): ?Photo
    {
        return Photo::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('asset_id', $asset->id)
            ->whereIn('category', self::FOTO_PUBBLICHE)
            ->orderByRaw("(category = 'census') DESC")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
