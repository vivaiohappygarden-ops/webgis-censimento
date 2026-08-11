<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
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
            new Middleware('can:works.manage', only: ['store', 'update', 'destroy', 'transition', 'attachAsset', 'detachAsset']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['team_id', 'client_id', 'area_id']);

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
            $q = '%'.$request->string('q').'%';
            $query->where(fn ($w) => $w->where('code', 'ilike', $q)->orWhere('title', 'ilike', $q));
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(ListQuery::perPage($request, 25, 100))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $user = $request->user();

        $workOrder = DB::transaction(function () use ($data, $user) {
            return WorkOrder::create([
                ...$data,
                'code' => $this->nextCode($user->tenant_id),
                'status' => 'draft',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
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
                'assets.asset' => fn ($q) => $q->select('assets.id', 'census_code', 'object_type_id', 'status')
                    ->with('objectType:id,code,name'),
                'assets.workType:id,code,name,unit',
            ])
            ->findOrFail($id);

        return response()->json([
            'data' => [
                ...$workOrder->toArray(),
                'allowed_transitions' => WorkOrder::TRANSITIONS[$workOrder->status] ?? [],
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
            // completato o annullato non si riscrive
            if (in_array($workOrder->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'work_order' => 'Un ordine completato o annullato non è modificabile.',
                ]);
            }

            $workOrder->fill($data);

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
            $workOrder->version += 1;
            $workOrder->updated_by = $request->user()->id;
            $workOrder->save();

            Audit::log('work_order.transition', $workOrder, ['to' => $data['status']]);
        });

        return $this->show($id);
    }

    public function attachAsset(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'uuid'],
            'work_type_id' => ['nullable', 'uuid'],
            'planned_quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
        ]);

        $workOrder = WorkOrder::query()->findOrFail($id);
        $this->assertNotTerminal($workOrder);
        $asset = Asset::query()->findOrFail($data['asset_id']);
        if (! empty($data['work_type_id'])) {
            \App\Models\WorkType::query()->findOrFail($data['work_type_id']);
        }

        try {
            WorkOrderAsset::create([
                'tenant_id' => $workOrder->tenant_id,
                'work_order_id' => $workOrder->id,
                'asset_id' => $asset->id,
                'work_type_id' => $data['work_type_id'] ?? null,
                'planned_quantity' => $data['planned_quantity'] ?? null,
                'unit' => $data['unit'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Il vincolo del DB è l'arbitro: anche due richieste simultanee
            // ricevono lo stesso 422, mai un errore interno
            throw ValidationException::withMessages([
                'asset_id' => 'Elemento già presente nell\'ordine di lavoro con questa lavorazione.',
            ]);
        }

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

    /** Numerazione per tenant: ODL-ANNO-progressivo, serializzata con advisory lock. */
    private function nextCode(string $tenantId): string
    {
        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 42))', ["wo:{$tenantId}"]);
        $year = now()->year;
        $next = (int) DB::selectOne(
            "SELECT COALESCE(MAX((substring(code FROM '\\d+$'))::int), 0) + 1 AS n
             FROM work_orders WHERE tenant_id = ? AND code LIKE ?",
            [$tenantId, "ODL-{$year}-%"],
        )->n;

        return sprintf('ODL-%d-%04d', $year, $next);
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
            'risks' => ['nullable', 'string'],
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
