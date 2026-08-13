<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\IrrigationSector;
use App\Models\IrrigationSystem;
use App\Models\WorkOrder;
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

/**
 * Irrigazione di base: un impianto per area con i suoi settori e il
 * programma stagionale; le manutenzioni diventano ordini di lavoro.
 */
class IrrigationController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:areas.view', only: ['index', 'show']),
            new Middleware('can:areas.update', only: ['store', 'update', 'destroy', 'syncSectors']),
            new Middleware('can:works.manage', only: ['createWorkOrder']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['area_id']);

        $query = IrrigationSystem::query()
            ->with('area:id,name,code')
            ->withCount('sectors');

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->string('area_id'));
        }

        return response()->json([
            'data' => $query->orderBy('name')->limit(500)->get(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'data' => IrrigationSystem::query()
                ->with(['area:id,name,code', 'sectors'])
                ->findOrFail($id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        Area::query()->findOrFail($data['area_id']);

        $system = IrrigationSystem::create([
            ...$data,
            'tenant_id' => $request->user()->tenant_id,
        ]);

        Audit::log('irrigation_system.created', $system, ['name' => $system->name]);

        // refresh(): i DEFAULT del DB (es. status) non tornano dalla sola create
        return response()->json(['data' => $system->refresh()->load('area:id,name,code')], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validated($request, required: false);
        unset($data['version']);

        DB::transaction(function () use ($request, $id, $data) {
            // Lock pessimistico: rende atomico il confronto di versione
            $system = IrrigationSystem::query()->lockForUpdate()->findOrFail($id);

            if ($request->filled('version') && (int) $request->input('version') !== $system->version) {
                abort(409, "Conflitto di versione: l'impianto è stato modificato da altri (versione attuale {$system->version}).");
            }

            if (isset($data['area_id'])) {
                Area::query()->findOrFail($data['area_id']);
            }

            $system->fill($data);
            // Aggiornando una sola delle due date la regola di validazione non
            // può confrontarle: il controllo va fatto sulla coppia risultante
            if ($system->season_opens_on && $system->season_closes_on
                && $system->season_closes_on->lt($system->season_opens_on)) {
                throw ValidationException::withMessages([
                    'season_closes_on' => 'La chiusura della stagione precede l\'apertura.',
                ]);
            }
            if ($system->isDirty()) {
                $system->version = $system->version + 1;
            }
            $system->save();
            Audit::log('irrigation_system.updated', $system);
        });

        return $this->show($id);
    }

    public function destroy(string $id): Response
    {
        $system = DB::transaction(function () use ($id) {
            $system = IrrigationSystem::query()->lockForUpdate()->findOrFail($id);
            // I settori non hanno soft delete né percorso di ripristino: vanno
            // rimossi insieme all'impianto, o resterebbero righe orfane per sempre
            IrrigationSector::query()->where('system_id', $system->id)->delete();
            $system->delete();

            return $system;
        });

        Audit::log('irrigation_system.deleted', $system, ['name' => $system->name]);

        return response()->noContent();
    }

    /** Sostituzione integrale dei settori (nessun riferimento esterno). */
    public function syncSectors(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'integer'],
            'sectors' => ['present', 'array', 'max:100'],
            'sectors.*.name' => ['required', 'string', 'max:120'],
            'sectors.*.description' => ['nullable', 'string', 'max:500'],
            'sectors.*.flow_lpm' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'sectors.*.run_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'sectors.*.runs_per_week' => ['nullable', 'integer', 'min:1', 'max:28'],
        ]);

        $names = array_map(fn ($s) => mb_strtolower(trim($s['name'])), $data['sectors']);
        if (count($names) !== count(array_unique($names))) {
            throw ValidationException::withMessages([
                'sectors' => 'Due settori hanno lo stesso nome: rinominane uno.',
            ]);
        }

        try {
            $system = DB::transaction(function () use ($id, $data, $request) {
                // Il lock serializza le sostituzioni concorrenti: senza, due
                // salvataggi insieme lascerebbero l'unione dei due elenchi
                $system = IrrigationSystem::query()->lockForUpdate()->findOrFail($id);

                if (isset($data['version']) && (int) $data['version'] !== $system->version) {
                    abort(409, "Conflitto di versione: l'impianto è stato modificato da altri (versione attuale {$system->version}).");
                }

                IrrigationSector::query()->where('system_id', $system->id)->delete();
                foreach ($data['sectors'] as $index => $sector) {
                    IrrigationSector::create([
                        'tenant_id' => $request->user()->tenant_id,
                        'system_id' => $system->id,
                        'sort_order' => $index + 1,
                        'name' => trim($sector['name']),
                        'description' => $sector['description'] ?? null,
                        'flow_lpm' => $sector['flow_lpm'] ?? null,
                        'run_minutes' => $sector['run_minutes'] ?? null,
                        'runs_per_week' => $sector['runs_per_week'] ?? null,
                    ]);
                }
                $system->version = $system->version + 1;
                $system->save();

                return $system;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Due salvataggi simultanei sugli stessi settori: uno dei due perde
            throw ValidationException::withMessages([
                'sectors' => 'Salvataggio simultaneo da un altro utente: ricarica l\'impianto e riprova.',
            ]);
        }

        Audit::log('irrigation_system.sectors_updated', $system, ['count' => count($data['sectors'])]);

        return $this->show($id);
    }

    /** Ordine di manutenzione in bozza, pronto da pianificare. */
    public function createWorkOrder(Request $request, string $id): JsonResponse
    {
        $system = IrrigationSystem::query()->with('area:id,name')->findOrFail($id);
        $user = $request->user();

        // In transazione: il lock advisory di nextCode vive fino al commit,
        // così due richieste simultanee non calcolano lo stesso codice
        $workOrder = DB::transaction(fn () => WorkOrder::create([
            'tenant_id' => $user->tenant_id,
            'code' => WorkOrder::nextCode($user->tenant_id),
            'title' => "Manutenzione irrigazione: {$system->name}".($system->area ? " ({$system->area->name})" : ''),
            'status' => 'draft',
            'origin' => 'maintenance_plan',
            'origin_id' => $system->id,
            'area_id' => $system->area_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]));

        Audit::log('work_order.created', $workOrder, ['code' => $workOrder->code, 'origin' => 'maintenance_plan']);

        return response()->json(['data' => $workOrder], 201);
    }

    private function validated(Request $request, bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return $request->validate([
            'version' => ['nullable', 'integer'],
            'area_id' => [$presence, 'uuid'],
            'name' => [$presence, 'string', 'max:150'],
            'system_type' => ['sometimes', Rule::in(IrrigationSystem::TYPES)],
            'water_source' => ['nullable', Rule::in(IrrigationSystem::WATER_SOURCES)],
            'controller_model' => ['nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(IrrigationSystem::STATUSES)],
            'season_opens_on' => ['nullable', 'date'],
            'season_closes_on' => ['nullable', 'date', 'after_or_equal:season_opens_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
