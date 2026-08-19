<?php

namespace App\Http\Controllers\Portale;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Photo;
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

        $lat = (float) $dati->lat;
        $lon = (float) $dati->lon;

        $dativista = [
            'portale' => $portale,
            'asset' => $asset,
            'stato' => $dati->stato,
            'lat' => $lat,
            'lon' => $lon,
            'hasFoto' => $this->fotoPubblica($asset) !== null,
            'urlNavigazione' => $this->conCoordinate(config('portal.navigation_url'), $lat, $lon),
            'urlPosizione' => $this->conCoordinate(config('portal.position_url'), $lat, $lon),
            'urlSegnalazione' => $this->urlSegnalazione($portale, $asset),
            'cronologia' => \App\Services\Portale\AssetTimeline::per($portale->client, $asset),
            'co2' => $portale->mostraCo2()
                ? \App\Services\Benefits\CarbonEstimate::per($asset->tree)
                : null,
            'vincoli' => $this->vincoli($portale, $asset),
        ];

        // Richiesta dal pannello laterale della mappa: serve solo la scheda,
        // senza intestazione né piè di pagina
        return $request->boolean('riquadro')
            ? view('portale.scheda', $dativista)
            : view('portale.elemento', $dativista);
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

        $jpeg = \App\Services\Photos\PublicPhotoCache::jpeg($foto);
        abort_if($jpeg === null, 404);

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto.jpg"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Foto di un evento della cronologia. Vale solo per le foto collegate a
     * un atto pubblicabile: una foto non collegata, o collegata a un lavoro
     * non pubblicato, non è raggiungibile nemmeno conoscendone l'indirizzo.
     */
    public function fotoEvento(Request $request, PortalContext $portale)
    {
        $asset = PortalSearch::perRiferimento($portale->client, (string) $request->route('codice'));
        abort_if($asset === null, 404);

        $foto = Photo::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('asset_id', $asset->id)
            ->where('tenant_id', $portale->client->tenant_id)
            ->whereKey((string) $request->route('foto'))
            ->first();

        abort_if($foto === null, 404);
        abort_unless(in_array($foto->category, ['before', 'during', 'after', 'census', 'reference'], true), 404);
        abort_unless($this->eventoPubblico($foto), 404);

        $jpeg = \App\Services\Photos\PublicPhotoCache::jpeg($foto);
        abort_if($jpeg === null, 404);

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto.jpg"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Vincoli che gravano sull'elemento. Escono solo quelli contrassegnati
     * come pubblicabili e quelli del committente giusto: un vincolo di un
     * altro Comune non deve comparire qui.
     */
    private function vincoli(PortalContext $portale, Asset $asset): array
    {
        return DB::table('asset_constraints as ac')
            ->join('land_constraints as v', 'v.id', '=', 'ac.constraint_id')
            ->where('ac.asset_id', $asset->id)
            ->where('v.tenant_id', $portale->client->tenant_id)
            ->whereNull('v.deleted_at')
            ->where('v.is_public', true)
            ->where(fn ($w) => $w->whereNull('v.client_id')->orWhere('v.client_id', $portale->client->id))
            ->orderBy('v.code')
            ->get(['v.id', 'v.code', 'v.name', 'v.description', 'v.authority', 'v.document_id'])
            ->all();
    }

    /**
     * Documento del vincolo, scaricabile dal cittadino. È raggiungibile solo
     * attraverso il portale del committente e solo per i vincoli pubblicati:
     * il file non ha un indirizzo diretto.
     */
    public function documentoVincolo(Request $request, PortalContext $portale)
    {
        $vincolo = DB::table('land_constraints as v')
            ->join('documents as d', 'd.id', '=', 'v.document_id')
            ->where('v.id', (string) $request->route('vincolo'))
            ->where('v.tenant_id', $portale->client->tenant_id)
            ->whereNull('v.deleted_at')
            ->whereNull('d.deleted_at')
            ->where('v.is_public', true)
            ->where(fn ($w) => $w->whereNull('v.client_id')->orWhere('v.client_id', $portale->client->id))
            ->first(['v.code', 'd.s3_key', 'd.mime_type', 'd.title']);

        abort_if($vincolo === null, 404);

        $disk = Storage::disk();
        abort_unless($disk->exists($vincolo->s3_key), 404);

        // Il nome del file mostrato al cittadino è quello del vincolo, non
        // quello con cui è stato caricato dall'ufficio
        $nome = preg_replace('/[^A-Za-z0-9._-]+/', '-', $vincolo->code).'.pdf';

        return response($disk->get($vincolo->s3_key), 200, [
            'Content-Type' => $vincolo->mime_type ?: 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** Vero se la foto è agganciata a un lavoro o a una perizia pubblicati. */
    private function eventoPubblico(Photo $foto): bool
    {
        if ($foto->subject_type === null || $foto->subject_id === null) {
            return false;
        }

        return match ($foto->subject_type) {
            \App\Models\WorkOrder::class => \App\Models\WorkOrder::query()->withoutGlobalScopes()
                ->whereNull('deleted_at')->whereKey($foto->subject_id)
                ->where('is_public', true)->where('status', 'completed')->exists(),
            \App\Models\TreeAssessment::class => \App\Models\TreeAssessment::query()->withoutGlobalScopes()
                ->whereNull('deleted_at')->whereKey($foto->subject_id)
                ->where('is_public', true)->exists(),
            default => false,
        };
    }

    private function conCoordinate(?string $modello, float $lat, float $lon): ?string
    {
        if (empty($modello)) {
            return null;
        }

        return str_replace(
            ['{lat}', '{lon}'],
            [number_format($lat, 6, '.', ''), number_format($lon, 6, '.', '')],
            $modello,
        );
    }

    /**
     * Segnalazione del cittadino: per ora è una mail all'ente, come sui
     * portali di riferimento. L'oggetto porta già l'etichetta dell'elemento,
     * così chi la riceve sa subito di quale albero si parla.
     */
    private function urlSegnalazione(PortalContext $portale, Asset $asset): ?string
    {
        $mail = $portale->contactEmail();
        if ($mail === null) {
            return null;
        }

        $etichetta = $asset->census_code ?: substr($asset->id, 0, 8);
        // L'indirizzo della scheda è quello della richiesta in corso: sul
        // sottodominio del Comune deve restare il sottodominio
        $indirizzo = url()->current();

        return 'mailto:'.$mail.'?'.http_build_query([
            'subject' => "Segnalazione su elemento {$etichetta}",
            'body' => "Segnalo un problema sull'elemento {$etichetta}.\n\nScheda: {$indirizzo}\n\nDescrizione:\n",
        ], '', '&', PHP_QUERY_RFC3986);
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
