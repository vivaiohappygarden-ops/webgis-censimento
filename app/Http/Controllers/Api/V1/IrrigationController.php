<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\IrrigationMeterReading;
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
            new Middleware('can:areas.view', only: ['index', 'show', 'readings']),
            new Middleware('can:areas.update', only: ['store', 'update', 'destroy', 'syncSectors', 'storeReading', 'destroyReading']),
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
            // Settori e letture non hanno soft delete né percorso di ripristino:
            // vanno rimossi insieme all'impianto, o resterebbero orfani per sempre
            IrrigationSector::query()->where('system_id', $system->id)->delete();
            IrrigationMeterReading::query()->where('system_id', $system->id)->delete();
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

    /**
     * Letture del contatore, dalla più recente: ogni lettura porta il consumo
     * rispetto alla precedente; il riepilogo confronta il consumo reale con la
     * stima settimanale ricavata dal programma dei settori.
     */
    public function readings(string $id): JsonResponse
    {
        $system = IrrigationSystem::query()->with('sectors')->findOrFail($id);

        $readings = IrrigationMeterReading::query()
            ->where('system_id', $system->id)
            ->orderBy('read_on')->orderBy('created_at')
            ->limit(500)->get();

        $rows = [];
        $previous = null;
        foreach ($readings as $reading) {
            $consumption = null;
            $days = null;
            if ($previous !== null) {
                $delta = round((float) $reading->value_m3 - (float) $previous->value_m3, 2);
                $days = $previous->read_on->diffInDays($reading->read_on);
                // Un valore più basso della lettura precedente indica un
                // contatore azzerato o sostituito: il consumo non è calcolabile
                $consumption = $delta >= 0 ? $delta : null;
            }
            $rows[] = [
                'id' => $reading->id,
                'read_on' => $reading->read_on->toDateString(),
                'value_m3' => (float) $reading->value_m3,
                'note' => $reading->note,
                'consumption_m3' => $consumption,
                'days_since_previous' => $days,
            ];
            $previous = $reading;
        }

        // Stima dal programma: portata (l/min) x minuti x cicli/settimana
        $weeklyEstimate = round($system->sectors->sum(fn ($s) => ((float) ($s->flow_lpm ?? 0)) * ($s->run_minutes ?? 0) * ($s->runs_per_week ?? 0)
        ) / 1000, 2);

        // Consumo reale settimanale dalle ultime due letture confrontabili
        $actualWeekly = null;
        $last = end($rows);
        if ($last && $last['consumption_m3'] !== null && ($last['days_since_previous'] ?? 0) > 0) {
            $actualWeekly = round($last['consumption_m3'] / $last['days_since_previous'] * 7, 2);
        }

        return response()->json([
            'data' => array_reverse($rows),
            'summary' => [
                'weekly_estimate_m3' => $weeklyEstimate,
                'actual_weekly_m3' => $actualWeekly,
            ],
        ]);
    }

    public function storeReading(Request $request, string $id): JsonResponse
    {
        $system = IrrigationSystem::query()->findOrFail($id);

        $data = $request->validate([
            'read_on' => ['required', 'date', 'before_or_equal:today'],
            'value_m3' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            // In transazione (savepoint): il fallimento del vincolo UNIQUE non
            // deve avvelenare una eventuale transazione esterna
            $reading = DB::transaction(fn () => IrrigationMeterReading::create([
                'tenant_id' => $request->user()->tenant_id,
                'system_id' => $system->id,
                'read_on' => $data['read_on'],
                'value_m3' => $data['value_m3'],
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'read_on' => 'Esiste già una lettura per quella data: eliminala o scegli un altro giorno.',
            ]);
        }

        Audit::log('irrigation_reading.created', $reading, [
            'system' => $system->name, 'read_on' => $data['read_on'], 'value_m3' => $data['value_m3'],
        ]);

        return $this->readings($id)->setStatusCode(201);
    }

    public function destroyReading(string $id, string $readingId): JsonResponse
    {
        $reading = IrrigationMeterReading::query()
            ->where('system_id', $id)->findOrFail($readingId);
        $reading->delete();

        Audit::log('irrigation_reading.deleted', $reading, ['read_on' => $reading->read_on->toDateString()]);

        return $this->readings($id);
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
