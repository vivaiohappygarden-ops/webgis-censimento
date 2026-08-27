<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Models\Area;
use App\Models\Asset;
use App\Models\CatalogObjectType;
use App\Support\Audit;
use App\Support\Geometry;
use App\Support\ListQuery;
use App\Support\RicercaTestuale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AssetController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index', 'show', 'versioni', 'quantita']),
            new Middleware('can:assets.create', only: ['store']),
            new Middleware('can:assets.update', only: ['update', 'registerRemoval', 'cancelRemoval']),
            new Middleware('can:assets.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['area_id', 'object_type_id', 'client_id', 'locality_id']);

        $query = Asset::query()
            ->with([
                'objectType:id,code,name,allowed_geometry',
                // Committente e area: servono a video per capire di chi è
                // ogni elemento senza aprire la scheda
                'area:id,name,locality_id',
                'area.locality:id,name,site_id',
                'area.locality.site:id,name,client_id',
                'area.locality.site.client:id,name',
            ])
            ->selectRaw('assets.*, ST_AsGeoJSON(geom)::json AS geom_geojson')
            ->withCasts(['geom_geojson' => 'array']);

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->string('area_id'));
        }
        if ($request->filled('locality_id')) {
            $query->whereHas('area', fn ($w) => $w->where('locality_id', $request->string('locality_id')));
        }
        // Committente: assets -> aree -> località -> sedi -> cliente
        if ($request->filled('client_id')) {
            $query->whereHas('area.locality.site', fn ($w) => $w->where('client_id', $request->string('client_id')));
        }
        if ($request->filled('object_type_id')) {
            $query->where('object_type_id', $request->string('object_type_id'));
        }
        if ($request->filled('type_code')) {
            $query->whereHas('objectType', fn ($w) => $w->where('code', $request->string('type_code')));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        // Gli elementi abbattuti restano in archivio ma sporcano l'elenco di
        // tutti i giorni: chi consulta decide se vederli (default: si vedono,
        // così le altre pagine che usano questa API non cambiano)
        if ($request->boolean('hide_removed')) {
            $query->where('status', '!=', 'removed');
        }
        if ($request->filled('q')) {
            $request->validate(['q' => RicercaTestuale::regole()]);
            $query->cercaTesto($request->string('q'));
        }
        if ($request->filled('bbox')) {
            $query->whereRaw('geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)', ListQuery::bbox($request));
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(ListQuery::perPage($request, 50, 200))
        );
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $data = $request->validated();

        $type = CatalogObjectType::findOrFail($data['object_type_id']);
        Area::findOrFail($data['area_id']);
        $this->assertGeometryMatchesType($data['geometry']['type'], $type);
        $data['attributes'] = app(\App\Services\Catalog\AttributeValidator::class)
            ->validate($type, $data['attributes'] ?? []);

        $asset = DB::transaction(function () use ($data, $request, $type) {
            $asset = new Asset([
                ...collect($data)->except('geometry')->all(),
                'geom' => Geometry::toEwkb($data['geometry']),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            // Codice etichetta assegnato dal server quando il committente ha
            // un prefisso (MEN-0042): il lock è di transazione, quindi la
            // creazione dell'elemento deve stare qui dentro
            if (empty($asset->census_code)) {
                $asset->census_code = \App\Support\PortalLabels::nextCode($data['area_id']);
            }

            $this->assertValidityDates($asset);
            $asset->save();

            if ($type->requires_tree_record) {
                \App\Models\Tree::create(['asset_id' => $asset->id, 'tenant_id' => $asset->tenant_id]);
            }
            if ($type->is_planting_site) {
                \App\Models\PlantingSite::create(['asset_id' => $asset->id, 'tenant_id' => $asset->tenant_id]);
            }

            return $asset;
        });

        Audit::log('asset.created', $asset, ['type' => $type->code]);

        return response()->json(['data' => $this->show($asset->id)->getData()->data], 201);
    }

    public function show(string $id): JsonResponse
    {
        $asset = Asset::query()
            ->with([
                'objectType:id,code,name,allowed_geometry',
                'area:id,name,code',
                'tags' => fn ($q) => $q->whereIn('status', ['active', 'unassigned']),
                'photos' => fn ($q) => $q->orderByDesc('created_at'),
                'tree',
                'plantingSite',
            ])
            ->withCount(['photos', 'documents', 'versions'])
            ->selectRaw('assets.*, ST_AsGeoJSON(geom)::json AS geom_geojson')
            ->withCasts(['geom_geojson' => 'array'])
            ->findOrFail($id);

        return response()->json(['data' => $asset]);
    }

    /**
     * La quantità che la geometria dell'elemento propone nell'unità indicata
     * (regola unica in QuantitaDaGeometria): la usa il preventivo quando una
     * voce viene collegata a un elemento censito.
     */
    public function quantita(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['unit' => ['required', 'string', 'max:20']]);
        $asset = Asset::query()->findOrFail($id);

        return response()->json(['data' => [
            'quantity' => \App\Services\Works\QuantitaDaGeometria::perAsset($data['unit'], $asset),
            'unit' => $data['unit'],
            'tipo_misura' => \App\Services\Works\QuantitaDaGeometria::tipoMisura($data['unit']),
        ]]);
    }

    /** La storia delle modifiche: chi, quando e che cosa e' cambiato. */
    public function versioni(string $id): JsonResponse
    {
        // Scheda, fotografie e geometria si leggono in un colpo solo: il
        // blocco condiviso sulla riga tiene in attesa un salvataggio
        // concorrente (che parte sempre dal lock della stessa riga), che a
        // meta' lettura farebbe sparire la revisione appena creata
        $storia = DB::transaction(function () use ($id) {
            $asset = Asset::query()->with(['tree', 'plantingSite'])->sharedLock()->findOrFail($id);

            return \App\Services\Assets\StoriaScheda::per($asset);
        });

        return response()->json(['data' => $storia]);
    }

    public function update(UpdateAssetRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $id, $data) {
            // Lock pessimistico sulla riga: rende atomico il confronto di versione
            // (senza, due update concorrenti con la stessa versione passerebbero entrambi)
            $asset = Asset::query()->lockForUpdate()->findOrFail($id);

            if (isset($data['version']) && (int) $data['version'] !== $asset->version) {
                abort(409, "Conflitto di versione: la scheda è stata modificata da altri (versione attuale {$asset->version}).");
            }
            unset($data['version']);

            // L'abbattimento non si tocca dalla modifica generica della
            // scheda: entrare o uscire da "abbattuto" da qui lascerebbe la
            // data di rimozione, la fine validità e la scheda albero
            // disallineate rispetto allo stato
            if (array_key_exists('status', $data) && $data['status'] !== $asset->status
                && ($data['status'] === 'removed' || $asset->status === 'removed')) {
                throw ValidationException::withMessages([
                    'status' => $asset->status === 'removed'
                        ? 'Questa scheda risulta abbattuta: per rimetterla in vita usa "Annulla abbattimento" in fondo alla scheda.'
                        : 'Per registrare un abbattimento usa "Registra abbattimento" in fondo alla scheda: serve anche la data.',
                ]);
            }

            $type = isset($data['object_type_id'])
                ? CatalogObjectType::findOrFail($data['object_type_id'])
                : $asset->objectType;

            if (array_key_exists('geometry', $data)) {
                $this->assertGeometryMatchesType($data['geometry']['type'], $type);
                $data['geom'] = Geometry::toEwkb($data['geometry']);
                unset($data['geometry']);
            } elseif (isset($data['object_type_id'])) {
                // Cambio di tipo senza nuova geometria: la geometria esistente
                // deve essere compatibile con il nuovo tipo (422, non errore SQL)
                $current = DB::selectOne('SELECT GeometryType(geom) AS t FROM assets WHERE id = ?', [$asset->id])->t;
                $this->assertGeometryMatchesType($current, $type);
            }

            if (array_key_exists('area_id', $data)) {
                Area::findOrFail($data['area_id']);
            }

            if (array_key_exists('attributes', $data)) {
                $data['attributes'] = app(\App\Services\Catalog\AttributeValidator::class)
                    ->validate($type, $data['attributes'] ?? []);
            }

            $treeData = $data['tree'] ?? null;
            $siteData = $data['planting_site'] ?? null;
            unset($data['tree'], $data['planting_site']);

            // La specializzazione si prepara PRIMA di salvare qualsiasi cosa:
            // la fotografia di versione scatta sull'aggiornamento di assets e
            // deve riprendere la scheda albero com'era, non come sta per
            // diventare. Per questo l'ordine e': prepara, salva assets (o
            // incrementa la versione), e solo alla fine salva albero e posto.
            $treeDirty = false;
            $siteDirty = false;

            if ($treeData !== null && $asset->tree) {
                $asset->tree->fill($treeData);
                $this->assertTreeDates($asset->tree);
                $treeDirty = $asset->tree->isDirty();
            }

            if ($siteData !== null && $asset->plantingSite) {
                if (! empty($siteData['previous_tree_id'])) {
                    // Deve essere un albero del tenant (404 se estraneo)
                    \App\Models\Tree::query()->findOrFail($siteData['previous_tree_id']);
                }
                $asset->plantingSite->fill($siteData);
                $siteDirty = $asset->plantingSite->isDirty();
            }

            $asset->fill($data);
            $asset->updated_by = $request->user()->id;
            $this->assertValidityDates($asset);
            $asset->save();

            // Anche le modifiche a scheda albero/posto libero incrementano la versione
            // della scheda: senza questo, il lock ottimistico non vedrebbe i salvataggi
            // concorrenti che toccano solo la specializzazione (la riga assets non cambia)
            if (($treeDirty || $siteDirty) && ! $asset->wasChanged()) {
                DB::update('UPDATE assets SET version = version + 1, updated_at = now(), updated_by = ? WHERE id = ?', [
                    $request->user()->id, $asset->id,
                ]);
            }

            if ($treeDirty) {
                $asset->tree->save();
            }
            if ($siteDirty) {
                $asset->plantingSite->save();
            }

            Audit::log('asset.updated', $asset);
        });

        return $this->show($id);
    }

    /**
     * Eliminazione della scheda: serve solo a cancellare un rilievo sbagliato.
     * Se l'elemento è già entrato nel lavoro (ordini, ispezioni, segnalazioni,
     * trattamenti…) l'eliminazione lascerebbe righe orfane e storia incoerente:
     * in quel caso si registra l'abbattimento o si dismette la scheda.
     */
    public function destroy(string $id): Response
    {
        $asset = Asset::findOrFail($id);

        $links = $this->linkedRecords($asset);
        if ($links !== []) {
            $elenco = implode(', ', $links);
            throw ValidationException::withMessages([
                'asset' => "Non si può eliminare questa scheda: è collegata a {$elenco}. ".
                    'Se la pianta è stata abbattuta usa "Registra abbattimento", '.
                    'altrimenti metti la scheda come dismessa.',
            ]);
        }

        DB::transaction(function () use ($asset) {
            // Il codice censimento torna libero solo se la riga sparisce
            // dall'indice unico: l'indice esclude già le schede eliminate
            $asset->delete();
            Audit::log('asset.deleted', $asset, [
                'census_code' => $asset->census_code,
                'type' => $asset->objectType?->code,
            ]);
        });

        return response()->noContent();
    }

    /**
     * Registrazione dell'abbattimento/rimozione in un colpo solo: stato della
     * scheda, data di fine validità, scheda albero (da cui dipende il bilancio
     * arboreo) e spegnimento della pagina pubblica col QR.
     */
    public function registerRemoval(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            // Una data futura toglierebbe dal censimento un elemento che c'è
            // ancora: qui si registra solo quello che è già successo.
            // Il confronto è sulla giornata italiana, non su quella del
            // server (UTC): dopo mezzanotte "oggi" per chi lavora in Italia
            // è ancora "domani" per il server, e la data proposta a video
            // verrebbe rifiutata
            'removed_on' => [
                'required', 'date_format:Y-m-d',
                'before_or_equal:'.now('Europe/Rome')->toDateString(),
            ],
            'removal_reason' => ['nullable', 'string', 'max:254'],
            'version' => ['sometimes', 'integer'],
        ], [
            'removed_on.before_or_equal' => 'La data di abbattimento non può essere nel futuro.',
        ], [
            'removed_on' => 'data di abbattimento',
            'removal_reason' => 'motivo',
        ]);

        DB::transaction(function () use ($request, $id, $data) {
            $asset = Asset::query()->lockForUpdate()->findOrFail($id);

            if (isset($data['version']) && (int) $data['version'] !== $asset->version) {
                abort(409, "Conflitto di versione: la scheda è stata modificata da altri (versione attuale {$asset->version}).");
            }

            $publicPageWasOn = $asset->public_token !== null;

            $asset->status = 'removed';
            $asset->valid_to = $data['removed_on'];
            $asset->removal_reason = $data['removal_reason'] ?? null;
            // Rilievo fatto dopo l'abbattimento (censimento di una ceppaia,
            // recupero di dati storici): la scheda non può finire prima di
            // cominciare, quindi la validità parte dalla stessa data
            if ($asset->valid_from && $asset->valid_from->gt($asset->valid_to)) {
                $asset->valid_from = $asset->valid_to;
            }
            // Un QR già stampato non deve più portare alla scheda di una
            // pianta che non c'è più
            $asset->public_token = null;
            $asset->updated_by = $request->user()->id;
            $this->assertValidityDates($asset);
            $asset->save();

            if ($asset->tree) {
                $asset->tree->removed_on = $data['removed_on'];
                $asset->tree->removal_reason = $data['removal_reason'] ?? null;
                $this->assertTreeDates($asset->tree);
                $asset->tree->save();
            }

            Audit::log('asset.removal_registered', $asset, [
                'removed_on' => $data['removed_on'],
                'reason' => $data['removal_reason'] ?? null,
                'public_page_disabled' => $publicPageWasOn,
            ]);
        });

        return $this->show($id);
    }

    /** Annullamento della registrazione (errore di inserimento): la scheda torna attiva. */
    public function cancelRemoval(Request $request, string $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id) {
            $asset = Asset::query()->lockForUpdate()->findOrFail($id);

            $asset->status = 'active';
            $asset->valid_to = null;
            $asset->removal_reason = null;
            $asset->updated_by = $request->user()->id;
            $asset->save();

            if ($asset->tree) {
                $asset->tree->removed_on = null;
                $asset->tree->removal_reason = null;
                $asset->tree->save();
            }

            Audit::log('asset.removal_cancelled', $asset);
        });

        return $this->show($id);
    }

    /**
     * Righe che dipendono dalla scheda, già scritte come "2 ispezioni" pronte
     * per il messaggio a video. Le tabelle con cancellazione logica contano
     * solo le righe ancora vive.
     *
     * @return list<string>
     */
    private function linkedRecords(Asset $asset): array
    {
        // tabella => [colonna, singolare, plurale]
        $sources = [
            'work_order_assets' => ['asset_id', 'ordine di lavoro', 'ordini di lavoro'],
            'estimate_items' => ['asset_id', 'voce di preventivo', 'voci di preventivo'],
            'work_logs' => ['asset_id', 'rapportino di lavoro', 'rapportini di lavoro'],
            'inspections' => ['asset_id', 'ispezione', 'ispezioni'],
            'issues' => ['asset_id', 'segnalazione', 'segnalazioni'],
            'non_conformities' => ['asset_id', 'non conformità', 'non conformità'],
            'phyto_treatments' => ['asset_id', 'trattamento fitosanitario', 'trattamenti fitosanitari'],
            'gestionale_dispatches' => ['asset_id', 'invio al gestionale', 'invii al gestionale'],
            'tree_assessments' => ['tree_id', 'valutazione di stabilità (VTA)', 'valutazioni di stabilità (VTA)'],
        ];

        $found = [];

        foreach ($sources as $table => [$column, $singolare, $plurale]) {
            $query = DB::table($table)->where($column, $asset->id);
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            if (($count = $query->count()) > 0) {
                $found[] = $count.' '.($count === 1 ? $singolare : $plurale);
            }
        }

        $tags = DB::table('asset_tags')
            ->where('asset_id', $asset->id)
            ->whereIn('status', ['active', 'unassigned'])
            ->count();
        if ($tags > 0) {
            $found[] = $tags.' '.($tags === 1 ? 'tag fisico ancora associato' : 'tag fisici ancora associati');
        }

        return $found;
    }

    private function assertGeometryMatchesType(string $geometryType, CatalogObjectType $type): void
    {
        $allowed = array_map('strtoupper', $type->allowedGeometryTypes());

        if (! in_array(strtoupper($geometryType), $allowed, true)) {
            throw ValidationException::withMessages([
                'geometry' => "Il tipo oggetto {$type->code} richiede una geometria ".
                    implode('/', $type->allowedGeometryTypes()).", ricevuta {$geometryType}.",
            ]);
        }
    }

    private function assertTreeDates(\App\Models\Tree $tree): void
    {
        if ($tree->removed_on && $tree->planted_on && $tree->removed_on->lt($tree->planted_on)) {
            throw ValidationException::withMessages([
                'tree.removed_on' => 'La data di rimozione non può precedere quella di impianto ('.$tree->planted_on->toDateString().').',
            ]);
        }
    }

    private function assertValidityDates(Asset $asset): void
    {
        $from = $asset->valid_from ?? now()->startOfDay();

        if ($asset->valid_to && $asset->valid_to->lt($from)) {
            throw ValidationException::withMessages([
                'valid_to' => 'La data di fine validità non può precedere quella di inizio ('.$from->toDateString().').',
            ]);
        }
    }
}
