<?php

namespace App\Http\Controllers\Portale;

use App\Http\Controllers\Controller;
use App\Services\Portale\PortalQuery;
use App\Services\Portale\PortalState;
use App\Support\PortalContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Mappa pubblica del patrimonio di un committente.
 *
 * I punti arrivano come riquadri vettoriali (le stesse "tile" della mappa
 * interna), filtrati sul committente riconosciuto dall'indirizzo. Le regole
 * di pubblicabilità sono quelle di PortalQuery, tradotte qui in SQL perché
 * il riquadro si genera con una sola interrogazione.
 */
class MappaController extends Controller
{
    public function mappa(PortalContext $portale)
    {
        return view('portale.mappa', [
            'portale' => $portale,
            'estensione' => \App\Services\Portale\PortalExtent::per($portale->client),
            // Dopo i tre sfondi di serie vengono quelli propri del Comune
            // (ortofoto, carta tecnica): l'ordine tiene ferma l'anteprima
            'sfondi' => array_merge(
                \App\Services\Portale\PortalExtent::sfondi(),
                \App\Services\Carto\SfondiCommittente::perMappa($portale->client),
            ),
            'stati' => collect(PortalState::ETICHETTE)
                ->map(fn ($etichetta, $codice) => [
                    'codice' => $codice,
                    'etichetta' => $etichetta,
                    'colore' => PortalState::COLORI[$codice],
                ])->values(),
        ]);
    }

    /**
     * Riquadro vettoriale con i punti pubblicabili e il loro stato.
     *
     * Le coordinate del riquadro si leggono dalla rotta e non dalla firma:
     * con un parametro risolto dal contenitore in testa, Laravel assegna
     * gli altri per posizione e arriverebbero valori sbagliati.
     */
    public function tile(Request $request, PortalContext $portale): Response
    {
        $z = (int) $request->route('z');
        $x = (int) $request->route('x');
        $y = (int) $request->route('y');

        abort_unless($z >= 0 && $z <= 22, 404);
        $max = 2 ** $z;
        abort_unless($x >= 0 && $x < $max && $y >= 0 && $y < $max, 404);

        $client = $portale->client;
        $stato = PortalState::sql('a');

        $sql = <<<SQL
            WITH bounds AS (SELECT ST_TileEnvelope(?, ?, ?) AS b),
            mvtgeom AS (
              SELECT
                ST_AsMVTGeom(ST_Transform(a.geom, 3857), bounds.b, 4096, 256, true) AS geom,
                coalesce(a.census_code, a.id::text) AS codice,
                t.code AS tipo,
                t.name AS tipo_nome,
                (tr.asset_id IS NOT NULL) AS albero,
                CASE WHEN tr.asset_id IS NULL THEN 'altro' ELSE ({$stato}) END AS stato
              FROM assets a
              JOIN catalog_object_types t ON t.id = a.object_type_id
              LEFT JOIN trees tr ON tr.asset_id = a.id AND tr.removed_on IS NULL
              CROSS JOIN bounds
              WHERE a.tenant_id = ?
                AND a.deleted_at IS NULL
                AND a.public_hidden = false
                AND a.status = 'active'
                AND (a.valid_to IS NULL OR a.valid_to >= CURRENT_DATE)
                AND a.area_id IN (
                  SELECT ar.id FROM areas ar
                  JOIN localities l ON l.id = ar.locality_id
                  JOIN sites s ON s.id = l.site_id
                  WHERE s.client_id = ? AND ar.tenant_id = a.tenant_id
                    AND ar.deleted_at IS NULL AND l.deleted_at IS NULL AND s.deleted_at IS NULL
                    AND ar.status IN ('active', 'suspended'))
                AND a.geom && ST_Transform(
                      ST_Expand(bounds.b, (ST_XMax(bounds.b) - ST_XMin(bounds.b)) * 256.0 / 4096.0), 4326)
            )
            SELECT ST_AsMVT(mvtgeom.*, 'elementi', 4096, 'geom') AS tile FROM mvtgeom
        SQL;

        $row = DB::selectOne($sql, [$z, $x, $y, $client->tenant_id, $client->id]);
        $tile = $row->tile ?? null;

        if (is_resource($tile)) {
            $tile = stream_get_contents($tile);
        }

        if ($tile === null || $tile === '') {
            return response('', 204);
        }

        return response($tile, 200, [
            'Content-Type' => 'application/vnd.mapbox-vector-tile',
            // Dato pubblico e lento a cambiare: la cache alleggerisce molto
            // il server quando la mappa viene esplorata
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /** Riquadro geografico del patrimonio, per aprire la mappa già inquadrata. */
}
