<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Models\Issue;
use App\Models\Photo;
use App\Models\TreeAssessment;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use App\Services\Maps\StaticMap;
use App\Services\Pdf\PdfRenderer;
use App\Services\Photos\PublicPhotoCache;
use App\Services\Portale\PortalState;
use App\Services\Portale\TerritorioCommittente;
use App\Support\AssetStatus;
use App\Support\FiltriElementi;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Il portale riservato del Comune (ufficio tecnico), dal 26/09/2026: mappa,
 * elenco e scheda degli elementi, lavori, documenti e fotografie del proprio
 * territorio, in sola lettura.
 *
 * Chi entra ha il solo permesso `portal.view`: nessuna delle chiamate del
 * gestionale gli risponde, quindi ogni dato passa da qui e ogni chiamata
 * delimita il territorio con TerritorioCommittente. Niente prezzi, niente note
 * interne, niente nomi di aree o committenti altrui.
 */
class PortaleComuneController extends Controller implements HasMiddleware
{
    public const FUSO = 'Europe/Rome';

    /** Etichette dell'esito della valutazione VTA, per l'ufficio del Comune. */
    public const ESITI = [
        'ok' => 'Idoneo: nessun intervento necessario',
        'monitor' => 'Da monitorare',
        'prescriptions' => 'Da sottoporre agli interventi prescritti',
        'fell' => 'Proposto l\'abbattimento',
    ];

    public const ESITI_ISPEZIONE = [
        'passed' => 'Conforme',
        'passed_with_remarks' => 'Conforme con osservazioni',
        'failed' => 'Non conforme',
        'not_completed' => 'Non completata',
    ];

    public static function middleware(): array
    {
        return [new Middleware('can:portal.view')];
    }

    // --- Mappa ----------------------------------------------------------------

    /**
     * Riquadro vettoriale del territorio: le aree del Comune (strato "aree")
     * e i suoi elementi in gestione con lo stato a quattro voci (strato
     * "elementi"). Stesse regole della mappa pubblica, ma qui l'archivio e i
     * nascosti dal portale pubblico ci sono: e' il patrimonio del Comune.
     */
    public function tile(Request $request, int $z, int $x, int $y): Response
    {
        abort_unless($z >= 0 && $z <= 22, 404);
        $max = 2 ** $z;
        abort_unless($x >= 0 && $x < $max && $y >= 0 && $y < $max, 404);

        $territorio = TerritorioCommittente::richiesto($request->user());
        if ($territorio->areaIds->isEmpty()) {
            return response('', 204);
        }

        $aree = $territorio->segnapostoAree();
        $archivio = AssetStatus::sqlArchivio();
        $stato = PortalState::sql('a');

        $sql = <<<SQL
            WITH bounds AS (SELECT ST_TileEnvelope(?, ?, ?) AS b),
            aree AS (
              SELECT
                ST_AsMVTGeom(ST_Transform(ar.geom, 3857), bounds.b, 4096, 64, true) AS geom,
                ar.id::text AS id,
                ar.name AS nome,
                ar.code AS codice
              FROM areas ar CROSS JOIN bounds
              WHERE ar.id IN ({$aree}) AND ar.deleted_at IS NULL AND ar.geom IS NOT NULL
                AND ar.geom && ST_Transform(
                      ST_Expand(bounds.b, (ST_XMax(bounds.b) - ST_XMin(bounds.b)) * 64.0 / 4096.0), 4326)
            ),
            elementi AS (
              SELECT
                ST_AsMVTGeom(ST_Transform(a.geom, 3857), bounds.b, 4096, 256, true) AS geom,
                a.id::text AS id,
                coalesce(a.census_code, '') AS codice,
                t.name AS tipo_nome,
                (tr.asset_id IS NOT NULL) AS albero,
                tr.crown_diameter_m AS chioma_m,
                CASE WHEN tr.asset_id IS NULL THEN 'altro' ELSE ({$stato}) END AS stato
              FROM assets a
              JOIN catalog_object_types t ON t.id = a.object_type_id
              LEFT JOIN trees tr ON tr.asset_id = a.id AND tr.removed_on IS NULL
              CROSS JOIN bounds
              WHERE a.tenant_id = ? AND a.deleted_at IS NULL
                AND a.status NOT IN ({$archivio})
                AND a.area_id IN ({$aree})
                AND a.geom && ST_Transform(
                      ST_Expand(bounds.b, (ST_XMax(bounds.b) - ST_XMin(bounds.b)) * 256.0 / 4096.0), 4326)
            )
            SELECT (SELECT ST_AsMVT(aree.*, 'aree', 4096, 'geom') FROM aree)
                || (SELECT ST_AsMVT(elementi.*, 'elementi', 4096, 'geom') FROM elementi) AS tile
        SQL;

        $row = DB::selectOne($sql, [
            $z, $x, $y,
            ...$territorio->legamiAree(),
            $territorio->client->tenant_id,
            ...$territorio->legamiAree(),
        ]);
        $tile = $row->tile ?? null;
        if (is_resource($tile)) {
            $tile = stream_get_contents($tile);
        }
        if ($tile === null || $tile === '') {
            return response('', 204);
        }

        return response($tile, 200, [
            'Content-Type' => 'application/vnd.mapbox-vector-tile',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    // --- Elementi -------------------------------------------------------------

    /**
     * L'elenco degli elementi in gestione, con specie, stato, ultima VTA e
     * numero di fotografie. Ricerca a parole come nel gestionale (cartellino,
     * specie, area): l'ufficio tecnico cerca anche per nome, non solo per
     * numero letto sul cartellino.
     */
    public function elementi(Request $request): JsonResponse
    {
        $territorio = TerritorioCommittente::richiesto($request->user());
        ListQuery::validateUuidFilters($request, ['area_id']);

        $query = $territorio->elementi()
            ->with(['objectType:id,code,name,allowed_geometry', 'area:id,name'])
            ->fuoriArchivio()
            ->cercaTesto($request->string('q')->toString());

        if ($request->filled('area_id')) {
            abort_unless($territorio->possiedeArea((string) $request->string('area_id')), 404);
            $query->where('assets.area_id', (string) $request->string('area_id'));
        }
        if ($request->string('tipo')->toString() === 'alberi') {
            $query->whereHas('tree', fn ($t) => $t->whereNull('removed_on'));
        } elseif ($request->string('tipo')->toString() === 'altri') {
            $query->whereDoesntHave('tree', fn ($t) => $t->whereNull('removed_on'));
        }
        if (in_array($request->string('stato')->toString(), array_keys(PortalState::ETICHETTE), true)) {
            $query->whereRaw('('.$this->sqlStato().') = ?', [$request->string('stato')->toString()]);
        }
        if (in_array($request->string('vta')->toString(), ['scaduta', 'in_scadenza', 'mai', 'valutato'], true)) {
            FiltriElementi::conVta($query, $request->string('vta')->toString());
        }

        $this->conDettagli($query);
        $query->orderByRaw('assets.census_code ASC NULLS LAST')->orderBy('assets.created_at');

        $pagina = $query->paginate(ListQuery::perPage($request, 50, 200));
        $pagina->getCollection()->transform(fn ($a) => [
            'id' => $a->id,
            'census_code' => $a->census_code,
            'tipo' => $a->objectType?->name,
            'geometria' => $a->objectType?->allowed_geometry,
            'albero' => $a->albero === true || $a->albero === 't' || $a->albero === 1,
            'specie' => $a->specie,
            'nome_comune' => $a->nome_comune,
            'area' => $a->area?->name,
            'area_id' => $a->area_id,
            'stato' => $a->stato,
            'stato_etichetta' => $a->stato === 'altro' ? null : PortalState::etichetta($a->stato),
            'vta_data' => $a->vta_data,
            'vta_classe' => $a->vta_classe,
            'vta_prossimo' => $a->vta_prossimo,
            'n_foto' => (int) $a->n_foto,
            'lon' => $a->lon !== null ? (float) $a->lon : null,
            'lat' => $a->lat !== null ? (float) $a->lat : null,
        ]);

        return response()->json($pagina);
    }

    /** La scheda in sola lettura: misure, stato, fotografie, stabilita', lavori, documenti e cronologia. */
    public function elemento(Request $request, string $id): JsonResponse
    {
        $territorio = TerritorioCommittente::richiesto($request->user());

        $asset = $territorio->elementi()
            ->with(['tree', 'objectType:id,code,name,allowed_geometry', 'area.locality.site'])
            ->withGeoJson()
            ->withCasts(['geom_geojson' => 'array'])
            ->selectRaw('ST_X(ST_Centroid(assets.geom)) AS lon, ST_Y(ST_Centroid(assets.geom)) AS lat')
            ->selectRaw('('.$this->sqlStato().') AS stato')
            ->findOrFail($id);

        $albero = $asset->tree;
        $foto = Photo::query()->where('asset_id', $asset->id)
            ->orderByRaw('COALESCE(taken_at, created_at) DESC')
            ->get(['id', 'taken_at', 'created_at', 'category']);

        $valutazioni = $albero
            ? TreeAssessment::query()->where('tree_id', $asset->id)
                ->orderByDesc('assessed_on')->orderByDesc('created_at')->get()
            : collect();

        $lavori = WorkOrder::query()
            ->with(['workType:id,name'])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereIn('id', WorkOrderAsset::query()->where('asset_id', $asset->id)->select('work_order_id'))
            ->where(fn ($w) => $territorio->ordini($w))
            ->orderByRaw('COALESCE(completed_at, planned_end, planned_start, created_at) DESC')
            ->get();
        $fattoPerLavoro = WorkOrderAsset::query()->where('asset_id', $asset->id)
            ->pluck('status', 'work_order_id');

        return response()->json(['data' => [
            'id' => $asset->id,
            'census_code' => $asset->census_code,
            'status' => $asset->status,
            'status_etichetta' => AssetStatus::label($asset->status),
            'stato' => $asset->stato,
            'stato_etichetta' => $asset->stato === 'altro' ? null : PortalState::etichetta($asset->stato),
            'tipo' => $asset->objectType?->name,
            'tipo_codice' => $asset->objectType?->code,
            'geometria' => $asset->objectType?->allowed_geometry,
            'area' => $asset->area?->name,
            'area_id' => $asset->area_id,
            'localita' => $asset->area?->locality?->name,
            'sede' => $asset->area?->locality?->site?->name,
            'rilevato_il' => ($asset->surveyed_at ?? $asset->created_at)?->toDateString(),
            'attributes' => $asset->attributes ?? [],
            'geom_geojson' => $asset->geom_geojson,
            'lon' => $asset->lon !== null ? (float) $asset->lon : null,
            'lat' => $asset->lat !== null ? (float) $asset->lat : null,
            'tree' => $albero ? [
                'genus' => $albero->genus,
                'species' => $albero->species,
                'cultivar' => $albero->cultivar,
                'common_name' => $albero->common_name,
                'height_m' => $albero->height_m,
                'dbh_cm' => $albero->dbh_cm,
                'trunk_circumference_cm' => $albero->trunk_circumference_cm,
                'trunk_count' => $albero->trunk_count,
                'crown_diameter_m' => $albero->crown_diameter_m,
                'crown_insertion_m' => $albero->crown_insertion_m,
                'age_years_est' => $albero->age_years_est,
                'age_class' => $albero->age_class,
                'vegetative_state' => $albero->vegetative_state,
                'is_monumental' => (bool) $albero->is_monumental,
                'is_protected' => (bool) $albero->is_protected,
                'planted_on' => $albero->planted_on,
                'removed_on' => $albero->removed_on,
                'removal_reason' => $albero->removed_on ? $albero->removal_reason : null,
            ] : null,
            'foto' => $foto->map(fn (Photo $f) => $this->presentaFoto($f))->values(),
            'valutazioni' => $valutazioni->map(fn (TreeAssessment $v) => $this->presentaValutazione($v))->values(),
            'lavori' => $lavori->map(fn (WorkOrder $l) => [
                ...$this->presentaLavoro($l, $territorio),
                'fatto_qui' => ($fattoPerLavoro[$l->id] ?? null) === 'done',
            ])->values(),
            'cronologia' => $this->cronologia($asset, $foto, $valutazioni, $lavori),
        ]]);
    }

    /**
     * La fotografia, ridotta come sul portale pubblico (una scheda ne apre
     * anche dieci, dal telefono). Esce solo se e' di un elemento del
     * territorio, di un lavoro coerente col committente o di una richiesta
     * del suo portale.
     */
    public function foto(Request $request, string $id)
    {
        $territorio = TerritorioCommittente::richiesto($request->user());
        $foto = Photo::query()->findOrFail($id);
        abort_unless($this->fotoVisibile($foto, $territorio), 404);

        $jpeg = PublicPhotoCache::jpeg($foto);
        if ($jpeg !== null) {
            return response($jpeg, 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        // Non ricodificabile (formato insolito): esce l'originale
        $disk = Storage::disk();
        abort_unless($disk->exists($foto->s3_key), 404);

        return $disk->response($foto->s3_key, $foto->original_filename, ['Cache-Control' => 'private, max-age=3600']);
    }

    // --- Lavori ---------------------------------------------------------------

    /** Gli ordini di lavoro del territorio: in programma e in corso (di serie), fatti, o tutti. */
    public function lavori(Request $request): JsonResponse
    {
        $territorio = TerritorioCommittente::richiesto($request->user());

        $query = WorkOrder::query()
            ->with(['area:id,name', 'workType:id,name'])
            ->withCount([
                'assets as elementi_totali',
                'assets as elementi_fatti' => fn ($q) => $q->where('work_order_assets.status', 'done'),
            ])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where(fn ($w) => $territorio->ordini($w));

        $stato = $request->string('stato')->toString() ?: 'aperti';
        if ($stato === 'aperti') {
            $query->whereIn('status', WorkOrder::FIELD_STATUSES)
                ->orderByRaw('planned_start ASC NULLS LAST')->orderBy('created_at');
        } elseif ($stato === 'fatti') {
            $query->where('status', 'completed')->orderByDesc('completed_at');
            if ($request->filled('anno')) {
                $query->whereRaw('EXTRACT(YEAR FROM completed_at AT TIME ZONE ?) = ?', [self::FUSO, (int) $request->integer('anno')]);
            }
        } else {
            $query->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
                ->orderByRaw('COALESCE(completed_at, planned_start, created_at) DESC');
        }

        $pagina = $query->paginate(ListQuery::perPage($request, 50, 200));
        $richieste = $this->richiestePerLavoro($territorio, $pagina->getCollection()->pluck('id')->all());
        $pagina->getCollection()->transform(fn (WorkOrder $l) => [
            ...$this->presentaLavoro($l, $territorio),
            'elementi_totali' => (int) $l->elementi_totali,
            'elementi_fatti' => (int) $l->elementi_fatti,
            'richiesta' => $richieste[$l->id] ?? null,
        ]);

        return response()->json($pagina);
    }

    /** Un ordine con la sua descrizione, gli elementi toccati e le fotografie del lavoro. */
    public function lavoro(Request $request, string $id): JsonResponse
    {
        $territorio = TerritorioCommittente::richiesto($request->user());

        $lavoro = WorkOrder::query()
            ->with(['area:id,name', 'workType:id,name'])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where(fn ($w) => $territorio->ordini($w))
            ->findOrFail($id);

        // Solo gli elementi del territorio: un ordine intestato al Comune
        // potrebbe toccare, per errore, un elemento di un'altra area
        $elementi = WorkOrderAsset::query()
            ->with(['asset' => fn ($q) => $q->withTrashed()->with(['tree:asset_id,species,common_name', 'objectType:id,name'])])
            ->where('work_order_id', $lavoro->id)
            ->whereIn('asset_id', $territorio->elementi()->select('assets.id'))
            ->get();

        $foto = Photo::query()
            ->where('subject_type', $lavoro->getMorphClass())->where('subject_id', $lavoro->id)
            ->orderByRaw('COALESCE(taken_at, created_at) DESC')
            ->get(['id', 'taken_at', 'created_at', 'category', 'asset_id']);

        return response()->json(['data' => [
            ...$this->presentaLavoro($lavoro, $territorio),
            'description' => $lavoro->description,
            'richiesta' => $this->richiestePerLavoro($territorio, [$lavoro->id])[$lavoro->id] ?? null,
            'elementi' => $elementi->map(fn (WorkOrderAsset $e) => [
                'asset_id' => $e->asset_id,
                'census_code' => $e->asset?->census_code,
                'tipo' => $e->asset?->objectType?->name,
                'specie' => $e->asset?->tree?->species,
                'nome_comune' => $e->asset?->tree?->common_name,
                'fatto' => $e->status === 'done',
                'quantita' => $e->planned_quantity,
                'unita' => $e->unit,
            ])->values(),
            'foto' => $foto->map(fn (Photo $f) => $this->presentaFoto($f))->values(),
        ]]);
    }

    // --- Documenti ------------------------------------------------------------

    /**
     * I documenti del territorio: le perizie di stabilita' emesse per i suoi
     * alberi e i verbali delle ispezioni chiuse sulle sue aree e sui suoi
     * elementi. Una valutazione senza perizia emessa non e' un documento: e'
     * lavoro in corso del tecnico.
     */
    public function documenti(Request $request): JsonResponse
    {
        $territorio = TerritorioCommittente::richiesto($request->user());

        $perizie = $this->perizieEmesse($territorio)
            ->with(['tree:asset_id,species,common_name'])
            ->orderByDesc('report_issued_at')
            ->limit(300)
            ->get();
        $cartellini = DB::table('assets')->whereIn('id', $perizie->pluck('tree_id')->unique())->pluck('census_code', 'id');

        $verbali = Inspection::query()
            ->with(['template:id,name,target', 'asset' => fn ($q) => $q->withTrashed()->select('id', 'census_code', 'area_id'), 'area:id,name'])
            ->whereNotNull('completed_at')
            ->where(fn ($w) => $w->whereIn('area_id', $territorio->areaIds)
                ->orWhereIn('asset_id', $territorio->elementi()->select('assets.id')))
            ->orderByDesc('completed_at')
            ->limit(300)
            ->get();

        return response()->json(['data' => [
            'perizie' => $perizie->map(fn (TreeAssessment $v) => [
                ...$this->presentaValutazione($v),
                'asset_id' => $v->tree_id,
                'census_code' => $cartellini[$v->tree_id] ?? null,
                'specie' => $v->tree?->species,
                'nome_comune' => $v->tree?->common_name,
            ])->values(),
            'verbali' => $verbali->map(fn (Inspection $i) => [
                'id' => $i->id,
                'modello' => $i->template?->name,
                'oggetto' => $i->asset?->census_code ?? ($territorio->possiedeArea($i->area_id) ? $i->area?->name : null),
                'asset_id' => $i->asset_id,
                'completata_il' => $i->completed_at?->setTimezone(self::FUSO)->toDateString(),
                'esito' => $i->outcome,
                'esito_etichetta' => self::ESITI_ISPEZIONE[$i->outcome] ?? $i->outcome,
                'pdf' => '/api/v1/portal/documenti/verbali/'.$i->id.'/pdf',
            ])->values(),
        ]]);
    }

    /** La perizia in PDF: lo stesso documento che stampa il tecnico, solo se emessa e di un albero del territorio. */
    public function perizia(Request $request, string $id, PeriziaController $perizie, PdfRenderer $renderer, StaticMap $mappa)
    {
        $territorio = TerritorioCommittente::richiesto($request->user());
        $valutazione = $this->perizieEmesse($territorio)->findOrFail($id);

        return $perizie->pdf($request, $valutazione->id, $renderer, $mappa);
    }

    /** Il verbale di ispezione in PDF, se l'ispezione e' chiusa e riguarda il territorio. */
    public function verbale(Request $request, string $id, PdfController $stampe, PdfRenderer $renderer)
    {
        $territorio = TerritorioCommittente::richiesto($request->user());
        $ispezione = Inspection::query()
            ->whereNotNull('completed_at')
            ->where(fn ($w) => $w->whereIn('area_id', $territorio->areaIds)
                ->orWhereIn('asset_id', $territorio->elementi()->select('assets.id')))
            ->findOrFail($id);

        return $stampe->inspection($request, $renderer, $ispezione->id);
    }

    // --- Pezzi comuni ---------------------------------------------------------

    private function perizieEmesse(TerritorioCommittente $territorio)
    {
        return TreeAssessment::query()
            ->whereNotNull('report_issued_at')
            ->whereIn('tree_id', $territorio->elementi()->select('assets.id'));
    }

    /** Lo stato a quattro voci per gli alberi, "altro" per il resto: la stessa regola della mappa. */
    private function sqlStato(): string
    {
        return 'CASE WHEN EXISTS (SELECT 1 FROM trees tr WHERE tr.asset_id = assets.id AND tr.removed_on IS NULL)
            THEN ('.PortalState::sql('assets').') ELSE \'altro\' END';
    }

    private function conDettagli($query): void
    {
        $ultima = fn (string $campo) => "(SELECT ta.{$campo} FROM tree_assessments ta
            WHERE ta.tree_id = assets.id AND ta.deleted_at IS NULL
            ORDER BY ta.assessed_on DESC, ta.created_at DESC LIMIT 1)";

        $query->selectRaw('assets.*')
            ->selectRaw('EXISTS (SELECT 1 FROM trees tr WHERE tr.asset_id = assets.id AND tr.removed_on IS NULL) AS albero')
            ->selectRaw('(SELECT tr.species FROM trees tr WHERE tr.asset_id = assets.id LIMIT 1) AS specie')
            ->selectRaw('(SELECT tr.common_name FROM trees tr WHERE tr.asset_id = assets.id LIMIT 1) AS nome_comune')
            ->selectRaw('('.$this->sqlStato().') AS stato')
            ->selectRaw($ultima('assessed_on').'::text AS vta_data')
            ->selectRaw($ultima('failure_class').' AS vta_classe')
            ->selectRaw($ultima('next_check_due').'::text AS vta_prossimo')
            ->selectRaw('(SELECT COUNT(*) FROM photos p WHERE p.asset_id = assets.id AND p.deleted_at IS NULL) AS n_foto')
            ->selectRaw('ST_X(ST_Centroid(assets.geom)) AS lon, ST_Y(ST_Centroid(assets.geom)) AS lat');
    }

    private function fotoVisibile(Photo $foto, TerritorioCommittente $territorio): bool
    {
        if ($foto->asset_id !== null) {
            return $territorio->elementi()->where('assets.id', $foto->asset_id)->exists();
        }
        if ($foto->subject_type === (new WorkOrder)->getMorphClass() && $foto->subject_id) {
            return WorkOrder::query()->where('id', $foto->subject_id)->where(fn ($w) => $territorio->ordini($w))->exists();
        }
        if ($foto->subject_type === 'issue' && $foto->subject_id) {
            return Issue::query()->where('id', $foto->subject_id)
                ->where('channel', 'client_portal')->where('client_id', $territorio->client->id)->exists();
        }

        return false;
    }

    private function presentaFoto(Photo $f): array
    {
        return [
            'id' => $f->id,
            'url' => '/api/v1/portal/foto/'.$f->id,
            'taken_at' => $f->taken_at?->toIso8601String(),
            'created_at' => $f->created_at?->toIso8601String(),
            'category' => $f->category,
        ];
    }

    private function presentaValutazione(TreeAssessment $v): array
    {
        $emessa = $v->report_issued_at !== null;

        return [
            'id' => $v->id,
            'assessed_on' => $v->assessed_on?->toDateString(),
            'failure_class' => $v->failure_class,
            'outcome' => $v->outcome,
            'outcome_etichetta' => self::ESITI[$v->outcome] ?? $v->outcome,
            'next_check_due' => $v->next_check_due?->toDateString(),
            'prescriptions' => $v->prescriptions,
            'report_number' => $emessa ? $v->report_number : null,
            'report_issued_at' => $v->report_issued_at?->setTimezone(self::FUSO)->toDateString(),
            'validated_at' => $v->validated_at?->setTimezone(self::FUSO)->toDateString(),
            'pdf' => $emessa ? '/api/v1/portal/documenti/perizie/'.$v->id.'/pdf' : null,
        ];
    }

    private function presentaLavoro(WorkOrder $l, TerritorioCommittente $territorio): array
    {
        return [
            'id' => $l->id,
            'code' => $l->code,
            'title' => $l->title,
            'status' => $l->status,
            'status_etichetta' => WorkOrder::STATUS_LABELS[$l->status] ?? $l->status,
            'tipo' => $l->workType?->name,
            // Il nome dell'area compare solo se e' davvero un'area del
            // cliente: mai nomi di aree altrui
            'area' => $territorio->possiedeArea($l->area_id) ? $l->area?->name : null,
            'planned_start' => $l->planned_start?->toDateString(),
            'planned_end' => $l->planned_end?->toDateString(),
            'completed_at' => $l->completed_at?->setTimezone(self::FUSO)->toDateString(),
        ];
    }

    /** @return array<string, array{code: string, id: string}> le richieste del portale che hanno generato ciascun lavoro */
    private function richiestePerLavoro(TerritorioCommittente $territorio, array $idLavori): array
    {
        if ($idLavori === []) {
            return [];
        }

        return Issue::query()
            ->where('channel', 'client_portal')->where('client_id', $territorio->client->id)
            ->whereIn('work_order_id', $idLavori)
            ->get(['id', 'code', 'work_order_id'])
            ->mapWithKeys(fn (Issue $i) => [$i->work_order_id => ['id' => $i->id, 'code' => $i->code]])
            ->all();
    }

    /**
     * La linea del tempo che il Comune puo' leggere: rilievo, valutazioni,
     * lavori, fotografie per giorno e abbattimento. Non le modifiche interne
     * della scheda: sono lavoro d'ufficio, non fatti del patrimonio.
     */
    private function cronologia($asset, $foto, $valutazioni, $lavori): array
    {
        $eventi = [];
        $rilievo = $asset->surveyed_at ?? $asset->created_at;
        $eventi[] = ['data' => $rilievo?->toDateString(), 'tipo' => 'rilievo',
            'titolo' => $asset->surveyed_at ? 'Rilievo in campo' : 'Inserimento nel censimento', 'dettaglio' => null];

        foreach ($valutazioni as $v) {
            $eventi[] = ['data' => $v->assessed_on?->toDateString(), 'tipo' => 'valutazione',
                'titolo' => 'Valutazione di stabilita\''.($v->failure_class ? ' · classe '.$v->failure_class : ''),
                'dettaglio' => implode(' · ', array_filter([
                    self::ESITI[$v->outcome] ?? null,
                    $v->next_check_due ? 'ricontrollo entro il '.$v->next_check_due->format('d/m/Y') : null,
                    $v->report_issued_at ? 'perizia n. '.$v->report_number : null,
                ])) ?: null];
        }

        foreach ($lavori as $l) {
            $data = $l->completed_at?->setTimezone(self::FUSO) ?? $l->planned_end ?? $l->planned_start ?? $l->created_at;
            $eventi[] = ['data' => $data?->toDateString(), 'tipo' => 'lavoro', 'titolo' => $l->title,
                'dettaglio' => implode(' · ', array_filter([$l->code, mb_strtolower(WorkOrder::STATUS_LABELS[$l->status] ?? $l->status), $l->workType?->name])) ?: null,
                'id' => $l->id];
        }

        foreach ($foto->groupBy(fn (Photo $f) => ($f->taken_at ?? $f->created_at)?->setTimezone(self::FUSO)->toDateString()) as $giorno => $gruppo) {
            $n = $gruppo->count();
            $eventi[] = ['data' => $giorno, 'tipo' => 'foto',
                'titolo' => $n === 1 ? 'Una fotografia' : "{$n} fotografie", 'dettaglio' => null];
        }

        if ($asset->tree?->removed_on) {
            $eventi[] = ['data' => Carbon::parse($asset->tree->removed_on)->toDateString(), 'tipo' => 'abbattimento',
                'titolo' => 'Abbattimento o rimozione', 'dettaglio' => $asset->tree->removal_reason];
        }

        // Stesso giorno: prima cio' che e' successo dopo (l'abbattimento
        // chiude, il rilievo apre)
        $priorita = ['abbattimento' => 0, 'lavoro' => 1, 'valutazione' => 2, 'foto' => 3, 'rilievo' => 4];
        usort($eventi, fn ($a, $b) => strcmp((string) $b['data'], (string) $a['data'])
            ?: ($priorita[$a['tipo']] <=> $priorita[$b['tipo']]));

        return $eventi;
    }
}
