<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use App\Services\Works\QuantitaDaGeometria;
use App\Support\RicercaTestuale;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.view', only: ['index', 'show']),
            new Middleware('can:works.manage', only: ['store', 'update', 'destroy', 'transition', 'toggleDay', 'attachAsset', 'updateAsset', 'detachAsset']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['team_id', 'client_id', 'area_id']);
        // La finestra va in coppia: un solo estremo sarebbe ignorato in silenzio
        // (nullable e non sometimes: required_with deve valere anche sul campo assente)
        $request->validate([
            'from' => ['nullable', 'required_with:to', 'date'],
            'to' => ['nullable', 'required_with:from', 'date', 'after_or_equal:from'],
            'unplanned' => ['sometimes', 'boolean'],
        ]);

        $query = WorkOrder::query()
            ->with(['team:id,name', 'assignee:id,name', 'workType:id,code,name,unit', 'area:id,name', 'client:id,name'])
            ->withCount('assets');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }
        foreach (['team_id', 'client_id', 'area_id'] as $key) {
            if ($request->filled($key)) {
                $query->where($key, $request->string($key));
            }
        }
        if ($request->filled('q')) {
            $request->validate(['q' => RicercaTestuale::regole()]);
            RicercaTestuale::applica($query, $request->string('q'), ['code', 'title', 'description']);
        }

        // Finestra dell'agenda: ordini il cui periodo previsto interseca [from, to]
        // (un ordine senza fine prevista occupa il solo giorno di inizio)
        $windowed = $request->filled('from') && $request->filled('to');
        if ($windowed) {
            $query->whereNotNull('planned_start')
                ->whereDate('planned_start', '<=', $request->date('to'))
                ->whereRaw('COALESCE(planned_end, planned_start) >= ?', [$request->date('from')->toDateString()]);
        }

        // "Da pianificare": ordini ancora vivi senza data di inizio prevista
        if ($request->boolean('unplanned')) {
            $query->whereNull('planned_start')
                ->whereNotIn('status', ['completed', 'cancelled']);
        }

        // In agenda l'ordine sensato è quello del calendario, non della creazione
        // L'id come ultimo criterio: senza un ordinamento univoco, a parita'
        // di data un ordine potrebbe comparire in due pagine diverse
        $query = $windowed
            ? $query->orderBy('planned_start')->orderByDesc('created_at')->orderBy('id')
            : $query->orderByDesc('created_at')->orderBy('id');

        return response()->json(
            $query->paginate(ListQuery::perPage($request, 25, 100))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->assertImpresaDelCommittente($data['team_id'] ?? null, $data['client_id'] ?? null);
        $user = $request->user();

        // Elementi da collegare subito (selezione sulla mappa): la quantità
        // prevista di ciascuno si propone dalla geometria, come nell'aggancio
        $extra = $request->validate([
            'asset_ids' => ['sometimes', 'array', 'max:200'],
            'asset_ids.*' => ['uuid'],
        ]);
        $assetIds = collect($extra['asset_ids'] ?? [])->unique()->values();
        $assets = collect();
        if ($assetIds->isNotEmpty()) {
            $assets = Asset::query()->whereIn('id', $assetIds)->get();
            if ($assets->count() !== $assetIds->count()) {
                throw ValidationException::withMessages([
                    'asset_ids' => 'Un elemento indicato non esiste.',
                ]);
            }
        }

        $workOrder = DB::transaction(function () use ($data, $user, $assets) {
            // Se gli elementi scelti stanno tutti nella stessa area e l'area
            // non è indicata, si eredita: sulla mappa non serve ripeterla
            if (empty($data['area_id']) && $assets->isNotEmpty()) {
                $areaIds = $assets->pluck('area_id')->unique();
                if ($areaIds->count() === 1 && $areaIds->first() !== null) {
                    $data['area_id'] = $areaIds->first();
                }
            }

            $workOrder = WorkOrder::create([
                ...$data,
                'code' => WorkOrder::nextCode($user->tenant_id),
                'status' => 'draft',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            foreach ($assets as $asset) {
                [$quantita, $unita] = $this->quantitaProposta($workOrder, $asset, [], null);
                WorkOrderAsset::create([
                    'tenant_id' => $workOrder->tenant_id,
                    'work_order_id' => $workOrder->id,
                    'asset_id' => $asset->id,
                    'planned_quantity' => $quantita,
                    'unit' => $unita,
                ]);
            }

            return $workOrder;
        });

        Audit::log('work_order.created', $workOrder, ['code' => $workOrder->code]);

        return response()->json(['data' => $this->show($workOrder->id)->getData()->data], 201);
    }

    public function show(string $id): JsonResponse
    {
        $workOrder = WorkOrder::query()
            ->with([
                'team:id,name', 'assignee:id,name', 'workType:id,code,name,unit',
                'area:id,name,code', 'client:id,name',
                'priceList:id,code,name,currency',
                'assets.asset' => fn ($q) => $q->select('assets.id', 'census_code', 'object_type_id', 'status', 'computed_area_sqm', 'computed_length_m')
                    ->with('objectType:id,code,name'),
                'assets.workType:id,code,name,unit',
                'logs' => fn ($q) => $q->with('operator:id,name')->orderByDesc('started_at'),
                'checks' => fn ($q) => $q->with('checker:id,name')->orderByDesc('checked_at'),
                'nonConformities' => fn ($q) => $q->with('responsible:id,name')->orderByDesc('created_at'),
            ])
            ->findOrFail($id);

        $payload = $workOrder->toArray();

        // Riga per riga, la misura che la geometria darebbe OGGI: l'interfaccia
        // la confronta con la quantità agganciata e, se il disegno è cambiato
        // nel frattempo, offre di riprenderla dalla mappa
        foreach ($workOrder->assets->values() as $i => $row) {
            $unit = $row->unit ?? $row->workType?->unit ?? $workOrder->workType?->unit;
            $payload['assets'][$i]['quantita_geometrica'] = $row->asset !== null
                ? QuantitaDaGeometria::perAsset($unit, $row->asset)
                : null;
            $payload['assets'][$i]['unita_geometrica'] = $unit;
        }

        $economics = app(\App\Services\Works\WorkOrderEconomics::class);

        return response()->json([
            'data' => [
                ...$payload,
                'allowed_transitions' => WorkOrder::TRANSITIONS[$workOrder->status] ?? [],
                'consuntivo' => $economics->consuntivo($workOrder),
                'previsto' => $economics->previsto($workOrder),
            ],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validated($request, required: false);

        DB::transaction(function () use ($request, $id, $data) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($id);
            $this->assertVersion($request, $workOrder);

            // Gli stati terminali sono immutabili: la storia di un ordine
            // completato o annullato non si riscrive. Unica eccezione: la
            // pubblicazione sul portale del committente, che è una decisione
            // editoriale e arriva per forza a lavoro concluso
            $soloPubblicazione = array_keys($data) === ['is_public'];
            if (in_array($workOrder->status, ['completed', 'cancelled'], true) && ! $soloPubblicazione) {
                throw ValidationException::withMessages([
                    'work_order' => 'Un ordine completato o annullato non è modificabile: si può solo pubblicarlo o toglierlo dal portale.',
                ]);
            }

            $workOrder->fill($data);
            // Un'impresa esterna lavora solo per il suo committente: il
            // controllo scatta quando squadra o committente CAMBIANO davvero.
            // Un ordine d'epoca fuori regola resta cosi' leggibile e
            // ritoccabile nel resto (date, descrizione, pubblicazione), ma
            // ogni cambio di squadra o committente deve approdare in regola
            if ($workOrder->isDirty('team_id') || $workOrder->isDirty('client_id')) {
                $this->assertImpresaDelCommittente($workOrder->team_id, $workOrder->client_id);
            }

            // L'invariante della macchina a stati vale anche qui: un ordine
            // assegnato o in corso non può restare senza squadra né responsabile
            if (in_array($workOrder->status, ['assigned', 'in_progress'], true)
                && $workOrder->team_id === null && $workOrder->assigned_to === null) {
                throw ValidationException::withMessages([
                    'team_id' => 'Un ordine assegnato o in corso deve avere una squadra o un responsabile.',
                ]);
            }

            $workOrder->updated_by = $request->user()->id;
            if ($workOrder->isDirty()) {
                $workOrder->version += 1;
            }
            $workOrder->save();

            Audit::log('work_order.updated', $workOrder);
        });

        return $this->show($id);
    }

    /** Cambio di stato validato dalla macchina a stati. */
    public function transition(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(WorkOrder::STATUSES)],
            'version' => ['sometimes', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($request, $id, $data) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($id);
            $this->assertVersion($request, $workOrder);

            if (! $workOrder->canTransitionTo($data['status'])) {
                throw ValidationException::withMessages([
                    'status' => "Passaggio non ammesso: da '{$workOrder->status}' a '{$data['status']}'.",
                ]);
            }
            if (in_array($data['status'], ['assigned', 'in_progress'], true)
                && $workOrder->team_id === null && $workOrder->assigned_to === null) {
                throw ValidationException::withMessages([
                    'status' => 'Assegnare una squadra o un responsabile prima di questo passaggio.',
                ]);
            }

            $workOrder->status = $data['status'];
            if ($data['status'] === 'completed') {
                $workOrder->completed_at = now();
            }
            $workOrder->version += 1;
            $workOrder->updated_by = $request->user()->id;
            $workOrder->save();

            Audit::log('work_order.transition', $workOrder, ['to' => $data['status']]);
        });

        return $this->show($id);
    }

    /**
     * Annulla (o ripristina) una singola giornata di un ordine pianificato
     * su più giorni: l'ordine resta uno solo, cambia il calendario. Per
     * annullare tutto il lavoro si usa il cambio di stato "annullato".
     */
    public function toggleDay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'day' => ['required', 'date_format:Y-m-d'],
            'cancelled' => ['required', 'boolean'],
            'version' => ['sometimes', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($request, $id, $data) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($id);
            $this->assertVersion($request, $workOrder);

            if (in_array($workOrder->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'day' => 'Un ordine completato o annullato non si ripianifica.',
                ]);
            }
            $start = $workOrder->planned_start?->toDateString();
            $end = $workOrder->planned_end?->toDateString() ?? $start;
            if ($start === null || $data['day'] < $start || $data['day'] > $end) {
                throw ValidationException::withMessages([
                    'day' => 'La giornata indicata non rientra nel periodo previsto del lavoro.',
                ]);
            }

            $days = collect($workOrder->cancelled_days ?? [])->filter()->unique();
            $days = $data['cancelled']
                ? $days->push($data['day'])->unique()
                : $days->reject(fn ($d) => $d === $data['day']);

            // Restano solo le giornate dentro il periodo: se il lavoro viene
            // ripianificato, le esclusioni vecchie non devono sopravvivere
            $days = $days->filter(fn ($d) => $d >= $start && $d <= $end)->sort()->values();

            if ($days->count() > 0 && $days->count() >= $this->plannedDaysCount($start, $end)) {
                throw ValidationException::withMessages([
                    'day' => 'Sarebbero annullate tutte le giornate: usa "Annullato" per chiudere l\'intero ordine.',
                ]);
            }

            $workOrder->cancelled_days = $days->all();
            $workOrder->version += 1;
            $workOrder->updated_by = $request->user()->id;
            $workOrder->save();

            Audit::log('work_order.day_toggled', $workOrder, [
                'day' => $data['day'], 'cancelled' => $data['cancelled'],
            ]);
        });

        return $this->show($id);
    }

    private function plannedDaysCount(string $start, string $end): int
    {
        return \Illuminate\Support\Carbon::parse($start)
            ->diffInDays(\Illuminate\Support\Carbon::parse($end)) + 1;
    }

    public function attachAsset(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'uuid'],
            'work_type_id' => ['nullable', 'uuid'],
            'planned_quantity' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'unit' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
        ]);

        $workOrder = WorkOrder::query()->findOrFail($id);
        $this->assertNotTerminal($workOrder);
        $asset = Asset::query()->findOrFail($data['asset_id']);

        // Stessa regola dell'azione multipla (AzioniMultiple::collegaElementi):
        // una scheda in archivio (abbattuta o dismessa) non è più patrimonio
        // su cui si lavora. Senza questa guardia l'aggancio singolo sarebbe
        // la porta di servizio che scavalca la regola
        if (\App\Support\AssetStatus::inArchivio($asset->status)) {
            throw ValidationException::withMessages([
                'asset_id' => 'Elemento in archivio ('.\App\Support\AssetStatus::label($asset->status).'): '.
                    'non si collega a un ordine di lavoro. Se serve, prima ripristina la scheda.',
            ]);
        }
        $rowType = ! empty($data['work_type_id'])
            ? \App\Models\WorkType::query()->findOrFail($data['work_type_id'])
            : null;

        $duplicateMessage = ValidationException::withMessages([
            'asset_id' => 'Elemento già presente nell\'ordine di lavoro con questa lavorazione.',
        ]);

        // Pre-controllo per il percorso normale (non avvelena la transazione
        // dei test); il catch sotto copre la vera race tra richieste simultanee
        $exists = WorkOrderAsset::query()
            ->where('work_order_id', $workOrder->id)
            ->where('asset_id', $asset->id)
            ->where('work_type_id', $data['work_type_id'] ?? null)
            ->exists();
        if ($exists) {
            throw $duplicateMessage;
        }

        [$quantita, $unita] = $this->quantitaProposta($workOrder, $asset, $data, $rowType);

        try {
            WorkOrderAsset::create([
                'tenant_id' => $workOrder->tenant_id,
                'work_order_id' => $workOrder->id,
                'asset_id' => $asset->id,
                'work_type_id' => $data['work_type_id'] ?? null,
                'planned_quantity' => $quantita,
                'unit' => $unita,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw $duplicateMessage;
        }

        return $this->show($id);
    }

    /**
     * Quantità e unità della riga: quelle indicate da chi aggancia, oppure la
     * proposta dalla geometria nell'unità della lavorazione (della riga o
     * dell'ordine). Regola in QuantitaDaGeometria, una volta sola.
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function quantitaProposta(WorkOrder $workOrder, Asset $asset, array $data, ?\App\Models\WorkType $rowType): array
    {
        if (isset($data['planned_quantity'])) {
            return [$data['planned_quantity'], $data['unit'] ?? null];
        }

        $unit = $data['unit'] ?? $rowType?->unit ?? $workOrder->workType?->unit;
        $quantita = QuantitaDaGeometria::perAsset($unit, $asset);

        return $quantita !== null ? [$quantita, $unit] : [null, $data['unit'] ?? null];
    }

    /** Quantità, unità e note di una riga elemento: modifica a mano o ricalcolo dalla geometria. */
    public function updateAsset(Request $request, string $id, string $rowId): JsonResponse
    {
        $data = $request->validate([
            // decimal:0,2: la colonna è numeric(12,2), senza questa regola il
            // DB arrotonderebbe in silenzio un valore con più decimali
            'planned_quantity' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'ricalcola' => ['sometimes', 'boolean'],
            'version' => ['sometimes', 'integer', 'min:1'],
        ]);

        // Come le altre modifiche all'ordine: blocco della riga e confronto
        // di versione, o due tecnici con il dettaglio aperto si
        // sovrascriverebbero la quantità prevista senza accorgersene
        DB::transaction(function () use ($request, $id, $rowId, $data) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($id);
            $this->assertVersion($request, $workOrder);
            $this->assertNotTerminal($workOrder);
            $row = WorkOrderAsset::query()
                ->where('work_order_id', $workOrder->id)
                ->with(['asset', 'workType'])
                ->findOrFail($rowId);

            if ($request->boolean('ricalcola')) {
                // Il ricalcolo riparte dalla geometria di OGGI: serve dopo un
                // ridisegno, quando la quantità agganciata è rimasta quella vecchia
                $unit = $row->unit ?? $row->workType?->unit ?? $workOrder->workType?->unit;
                $quantita = $row->asset !== null ? QuantitaDaGeometria::perAsset($unit, $row->asset) : null;
                if ($quantita === null) {
                    throw ValidationException::withMessages([
                        'ricalcola' => 'Dalla geometria di questo elemento non si ricava una quantità nell\'unità indicata.',
                    ]);
                }
                $row->planned_quantity = $quantita;
                $row->unit = $unit;
            } else {
                $row->fill(collect($data)->only(['planned_quantity', 'unit', 'notes'])->all());
            }
            $row->save();

            $workOrder->version += 1;
            $workOrder->updated_by = $request->user()->id;
            $workOrder->save();
        });

        return $this->show($id);
    }

    public function detachAsset(string $id, string $rowId): JsonResponse
    {
        $workOrder = WorkOrder::query()->findOrFail($id);
        $this->assertNotTerminal($workOrder);
        WorkOrderAsset::query()
            ->where('work_order_id', $workOrder->id)
            ->findOrFail($rowId)
            ->delete();

        return $this->show($id);
    }

    private function assertNotTerminal(WorkOrder $workOrder): void
    {
        if (in_array($workOrder->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'work_order' => 'Un ordine completato o annullato non è modificabile.',
            ]);
        }
    }

    public function destroy(string $id): Response
    {
        $workOrder = WorkOrder::query()->findOrFail($id);
        if (! in_array($workOrder->status, ['draft', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'work_order' => 'Si eliminano solo ordini in bozza o annullati: negli altri casi usare l\'annullamento.',
            ]);
        }
        $workOrder->delete();

        Audit::log('work_order.deleted', $workOrder, ['code' => $workOrder->code]);

        return response()->noContent();
    }

    /**
     * Un'impresa esterna appartiene a un committente (indicazione 28/08/2026):
     * le si affidano solo ordini di QUEL committente. Le squadre interne
     * lavorano per chiunque.
     */
    private function assertImpresaDelCommittente(?string $teamId, ?string $clientId): void
    {
        if ($teamId === null) {
            return;
        }
        $team = \App\Models\Team::query()->with('client:id,name')->find($teamId);
        if ($team === null || ! $team->is_external) {
            return;
        }
        if ($team->client_id === null) {
            throw ValidationException::withMessages([
                'team_id' => "L'impresa \"{$team->name}\" non è ancora collegata a un committente: collegala dalla pagina Utenti prima di affidarle lavori.",
            ]);
        }
        // La relazione puo' essere nulla anche con client_id valorizzato
        // (committente eliminato in passato): mai un errore grezzo
        $nomeCommittente = $team->client?->name;
        if ($nomeCommittente === null) {
            throw ValidationException::withMessages([
                'team_id' => "L'impresa \"{$team->name}\" è collegata a un committente eliminato: sistemala dalla pagina Utenti.",
            ]);
        }
        if ($clientId !== $team->client_id) {
            throw ValidationException::withMessages([
                'team_id' => "L'impresa \"{$team->name}\" lavora per {$nomeCommittente}: le si affidano solo ordini di quel committente.",
            ]);
        }
    }

    private function validated(Request $request, bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        $data = $request->validate([
            'title' => [$presence, 'string', 'max:254'],
            'description' => ['nullable', 'string'],
            'client_id' => ['nullable', 'uuid'],
            'area_id' => ['nullable', 'uuid'],
            'work_type_id' => ['nullable', 'uuid'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'planned_start' => ['nullable', 'date'],
            'planned_end' => ['nullable', 'date', 'after_or_equal:planned_start'],
            'due_at' => ['nullable', 'date'],
            'estimated_duration_min' => ['nullable', 'integer', 'min:0'],
            'team_id' => ['nullable', 'uuid'],
            'assigned_to' => ['nullable', 'uuid'],
            'price_list_id' => ['nullable', 'uuid'],
            'risks' => ['nullable', 'string'],
            // Pubblicazione dell'ordine come atto sul portale del committente
            'is_public' => ['sometimes', 'boolean'],
            'version' => ['sometimes', 'integer', 'min:1'],
        ]);
        unset($data['version']);

        // I riferimenti devono appartenere al tenant (404 se estranei)
        foreach ([
            'client_id' => \App\Models\Client::class,
            'area_id' => \App\Models\Area::class,
            'work_type_id' => \App\Models\WorkType::class,
            'team_id' => \App\Models\Team::class,
        ] as $key => $model) {
            if (! empty($data[$key])) {
                $model::query()->findOrFail($data[$key]);
            }
        }
        if (! empty($data['assigned_to'])) {
            \App\Models\User::query()->findOrFail($data['assigned_to']);
        }
        if (! empty($data['price_list_id'])) {
            $priceList = \App\Models\PriceList::query()->findOrFail($data['price_list_id']);
            // Gli ordini esistenti continuano a valorizzare col loro listino,
            // ma un listino disattivato non si applica a ordini nuovi o in corso
            if (! $priceList->is_active) {
                throw ValidationException::withMessages([
                    'price_list_id' => 'Listino disattivato: riattivalo oppure scegline uno attivo.',
                ]);
            }
        }

        return $data;
    }

    private function assertVersion(Request $request, WorkOrder $workOrder): void
    {
        $version = $request->input('version');
        if ($version !== null && (int) $version !== $workOrder->version) {
            abort(409, "Conflitto di versione: l'ordine è stato modificato da altri (versione attuale {$workOrder->version}).");
        }
    }
}
