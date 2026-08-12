<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Asset;
use App\Models\CatalogMainType;
use App\Models\CatalogObjectType;
use App\Models\CatalogSubType;
use App\Models\CustomField;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Sync\CommandApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * API di sincronizzazione della PWA operatore (OFFLINE-SYNC §5):
 * bootstrap del working set, pull delta a cursore, push batch idempotente.
 */
class SyncController extends Controller implements HasMiddleware
{
    public const MIN_CLIENT_SCHEMA = 1;

    private const MAX_COMMANDS_PER_BATCH = 200;

    private const MAX_CHANGES_PER_PULL = 500;

    public static function middleware(): array
    {
        return [new Middleware('can:assets.view')];
    }

    /** Download iniziale del working set, delimitato per aree. */
    public function bootstrap(Request $request): JsonResponse
    {
        $request->validate([
            'areas' => ['sometimes', 'array'],
            'areas.*' => ['uuid'],
        ]);

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $areaIds = $request->input('areas', []);

        // Il cursore fotografa solo le transazioni già concluse: quelle in corso
        // verranno riprese dal primo pull delta (al più con upsert ripetuti, mai persi)
        $cursor = (int) (DB::selectOne(
            'SELECT COALESCE(MAX(id), 0) AS c FROM change_log
             WHERE tenant_id = ?
               AND (txid < pg_snapshot_xmin(pg_current_snapshot()) OR txid = pg_current_xact_id())',
            [$tenantId],
        )->c);

        $areas = Area::query()
            ->when($areaIds !== [], fn ($q) => $q->whereIn('id', $areaIds))
            ->selectRaw('areas.*, ST_AsGeoJSON(geom)::json AS geom_geojson')
            ->withCasts(['geom_geojson' => 'array'])
            ->orderBy('name')
            ->get();

        $assets = $this->assetRows($areaIds);

        return response()->json([
            'cursor' => $cursor,
            'server_time' => now()->toIso8601String(),
            'min_client_schema' => self::MIN_CLIENT_SCHEMA,
            'catalog_version' => $this->catalogVersion($tenantId),
            'areas' => $areas,
            'assets' => $assets,
            'work_orders' => $user->can('works.view') ? $this->workOrderRows($user) : [],
            'inspection_templates' => $user->can('works.view') ? $this->inspectionTemplateRows() : [],
            'catalog' => [
                'main_types' => CatalogMainType::query()->orderBy('code')->get(),
                'sub_types' => CatalogSubType::query()->orderBy('code')->get(),
                'object_types' => CatalogObjectType::query()->orderBy('code')->get(),
            ],
            'custom_fields' => CustomField::query()->get(),
        ]);
    }

    /** Pull delta: record cambiati dal cursore in poi (sequenza server, non timestamp). */
    public function changes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['required', 'integer', 'min:0'],
            'areas' => ['sometimes', 'array'],
            'areas.*' => ['uuid'],
            // Righe puntuali richieste dal client (es. rimaste indietro perché
            // localmente dirty durante un pull precedente): fuori cursore
            'ids' => ['sometimes', 'array', 'max:200'],
            'ids.*' => ['uuid'],
        ]);

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $areaIds = $request->input('areas', []);

        // Una riga per entità (l'ultima modifica vince); si servono solo transazioni
        // già concluse (o la propria): una transazione lunga di un altro processo
        // con id bassi non viene mai scavalcata dal cursore
        $logRows = DB::select(<<<'SQL'
            SELECT table_name, row_id, MAX(id) AS last_id
            FROM change_log
            WHERE tenant_id = ? AND id > ?
              AND (txid < pg_snapshot_xmin(pg_current_snapshot()) OR txid = pg_current_xact_id())
            GROUP BY table_name, row_id
            ORDER BY last_id
            LIMIT ?
            SQL, [$tenantId, $data['cursor'], self::MAX_CHANGES_PER_PULL + 1]);

        $hasMore = count($logRows) > self::MAX_CHANGES_PER_PULL;
        $logRows = array_slice($logRows, 0, self::MAX_CHANGES_PER_PULL);
        $newCursor = $logRows === [] ? (int) $data['cursor'] : (int) end($logRows)->last_id;

        $byTable = collect($logRows)->groupBy('table_name')->map(fn ($g) => $g->pluck('row_id')->all());

        $changes = [];

        $changedAreaIds = $byTable->get('areas', []);
        if ($changedAreaIds !== []) {
            $changedAreas = Area::query()->withTrashed()
                ->whereIn('id', $changedAreaIds)
                ->selectRaw('areas.*, ST_AsGeoJSON(geom)::json AS geom_geojson')
                ->withCasts(['geom_geojson' => 'array'])
                ->get();
            foreach ($changedAreas as $area) {
                $changes[] = $area->deleted_at !== null
                    ? ['op' => 'delete', 'table' => 'areas', 'id' => $area->id]
                    : ['op' => 'upsert', 'table' => 'areas', 'row' => $area];
            }
        }

        // Gli id espliciti si aggiungono al delta (stesso formato, fuori cursore)
        $assetIds = collect($byTable->get('assets', []))
            ->merge($request->input('ids', []))
            ->unique()->values()->all();

        foreach ($this->assetRows($areaIds, $assetIds, withTrashed: true) as $asset) {
            $changes[] = $asset->deleted_at !== null
                ? ['op' => 'delete', 'table' => 'assets', 'id' => $asset->id]
                : ['op' => 'upsert', 'table' => 'assets', 'row' => $asset];
        }

        // Modelli di ispezione: gli attivi si aggiornano, i disattivati o
        // eliminati escono dal device come delete
        $templateIds = $byTable->get('inspection_templates', []);
        if ($templateIds !== [] && $user->can('works.view')) {
            $visibleTemplates = $this->inspectionTemplateRows($templateIds)->keyBy('id');
            foreach ($templateIds as $templateId) {
                $changes[] = isset($visibleTemplates[$templateId])
                    ? ['op' => 'upsert', 'table' => 'inspection_templates', 'row' => $visibleTemplates[$templateId]]
                    : ['op' => 'delete', 'table' => 'inspection_templates', 'id' => $templateId];
            }
        }

        // Ordini di lavoro: ciò che non è più visibile dal campo (completato,
        // annullato, riassegnato ad altri, eliminato) esce dal device come delete.
        // Gli id espliciti richiesti dal client possono essere anche ordini:
        // si riconoscono qui (scope tenant) e si servono con la stessa logica
        $requestedOrderIds = WorkOrder::query()->withTrashed()
            ->whereIn('id', $request->input('ids', []))
            ->pluck('id');
        $workOrderIds = collect($byTable->get('work_orders', []))
            ->merge($requestedOrderIds)
            ->unique()->values()->all();
        if ($workOrderIds !== [] && $user->can('works.view')) {
            $visible = $this->workOrderRows($user, $workOrderIds)->keyBy('id');
            foreach ($workOrderIds as $workOrderId) {
                $changes[] = isset($visible[$workOrderId])
                    ? ['op' => 'upsert', 'table' => 'work_orders', 'row' => $visible[$workOrderId]]
                    : ['op' => 'delete', 'table' => 'work_orders', 'id' => $workOrderId];
            }
        }

        return response()->json([
            'cursor' => $newCursor,
            'server_time' => now()->toIso8601String(),
            'catalog_version' => $this->catalogVersion($tenantId),
            'changes' => $changes,
            'has_more' => $hasMore,
        ]);
    }

    /** Push batch: applica i comandi in ordine, uno per transazione, replay-safe. */
    public function batch(Request $request, CommandApplier $applier): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'device_id' => ['required', 'string', 'max:100'],
            'schema' => ['required', 'integer'],
            'app_version' => ['nullable', 'string', 'max:30'],
            'client_time' => ['nullable', 'date'],
            'commands' => ['required', 'array', 'max:'.self::MAX_COMMANDS_PER_BATCH],
            'commands.*.idempotency_key' => ['required', 'uuid'],
            'commands.*.device_seq' => ['required', 'integer', 'min:0'],
            'commands.*.type' => ['required', 'string', 'max:60'],
            'commands.*.entity_id' => ['required', 'uuid'],
            'commands.*.base_version' => ['sometimes', 'integer', 'min:1'],
            'commands.*.payload' => ['sometimes', 'array'],
            'commands.*.geom' => ['sometimes', 'nullable', 'array'],
            'commands.*.client_ts' => ['sometimes', 'nullable', 'date'],
        ]);

        if ((int) $data['schema'] < self::MIN_CLIENT_SCHEMA) {
            abort(426, 'Versione dello schema client troppo vecchia: aggiorna l\'applicazione.');
        }

        $user = $request->user();
        $results = [];

        foreach ($data['commands'] as $command) {
            $results[] = $this->processCommand($command, $data, $user, $applier);
        }

        return response()->json([
            'batch_id' => $data['batch_id'],
            'server_time' => now()->toIso8601String(),
            'min_client_schema' => self::MIN_CLIENT_SCHEMA,
            'results' => $results,
        ]);
    }

    /**
     * Applica un comando in modo replay-safe: la chiave di idempotenza viene
     * reclamata (INSERT ... ON CONFLICT DO NOTHING) e l'esito registrato NELLA
     * STESSA transazione dell'applicazione. Due retry concorrenti producono un
     * solo apply; un crash a metà non consuma mai la chiave (Appendice A).
     */
    private function processCommand(array $command, array $envelope, $user, CommandApplier $applier): array
    {
        $key = $command['idempotency_key'];

        // Replay già registrato: risposta veloce senza transazione
        $existing = SyncOperation::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return ['idempotency_key' => $key, 'status' => 'duplicate', 'original' => $existing->result];
        }

        try {
            return DB::transaction(function () use ($command, $envelope, $user, $applier, $key) {
                $claimed = DB::affectingStatement(
                    'INSERT INTO sync_operations
                       (id, tenant_id, idempotency_key, batch_id, device_id, user_id, command_type, entity_id, status, result)
                     VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, NULL, ?, ?)
                     ON CONFLICT (tenant_id, idempotency_key) DO NOTHING',
                    [
                        $user->tenant_id, $key, $envelope['batch_id'], $envelope['device_id'],
                        $user->id, $command['type'], 'rejected', '{}',
                    ],
                );

                if ($claimed === 0) {
                    // Corsa persa contro un retry concorrente: l'attesa sul vincolo
                    // UNIQUE è finita quando l'altro worker ha committato il suo esito
                    $original = SyncOperation::query()->where('idempotency_key', $key)->first();

                    return [
                        'idempotency_key' => $key,
                        'status' => 'duplicate',
                        'original' => $original?->result ?? ['status' => 'error', 'code' => 'IN_PROGRESS'],
                    ];
                }

                $result = $applier->apply($command, $user);

                DB::table('sync_operations')
                    ->where('tenant_id', $user->tenant_id)
                    ->where('idempotency_key', $key)
                    ->update([
                        'entity_id' => $result['entity_id'] ?? null,
                        'status' => $result['status'],
                        'result' => json_encode($result),
                    ]);

                return $result;
            });
        } catch (\Throwable $e) {
            // Errore interno: la transazione (chiave compresa) è annullata, quindi
            // il comando è ritentabile; mai un 500 di envelope per un solo comando
            report($e);

            return [
                'idempotency_key' => $key,
                'status' => 'error',
                'code' => 'INTERNAL',
                'entity_id' => $command['entity_id'] ?? null,
                'message' => 'Errore interno nell\'applicazione del comando: verrà ritentato.',
            ];
        }
    }

    /** Righe asset complete di specializzazioni, nel formato del working set. */
    private function assetRows(array $areaIds, ?array $ids = null, bool $withTrashed = false)
    {
        if ($ids !== null && $ids === []) {
            return collect();
        }

        return Asset::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($ids !== null, fn ($q) => $q->whereIn('assets.id', $ids))
            ->when($areaIds !== [], fn ($q) => $q->whereIn('area_id', $areaIds))
            ->with(['tree', 'plantingSite', 'tags' => fn ($q) => $q->where('status', 'active')])
            ->selectRaw('assets.*, ST_AsGeoJSON(geom)::json AS geom_geojson')
            ->withCasts(['geom_geojson' => 'array'])
            ->orderBy('created_at')
            ->get();
    }

    /** Ordini di lavoro visibili dal campo, con lavorazione, area ed elementi. */
    private function workOrderRows(User $user, ?array $ids = null)
    {
        if ($ids !== null && $ids === []) {
            return collect();
        }

        return WorkOrder::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('work_orders.id', $ids))
            ->visibleInField($user)
            ->with([
                'workType:id,code,name,unit',
                'area:id,name,code',
                'team:id,name',
                'assets' => fn ($q) => $q
                    ->select('id', 'work_order_id', 'asset_id', 'work_type_id', 'planned_quantity', 'unit', 'status', 'notes')
                    ->with([
                        'asset:id,census_code,object_type_id,status',
                        'workType:id,code,name,unit',
                    ]),
            ])
            ->orderBy('created_at')
            ->get();
    }

    /** Modelli di ispezione attivi, con le domande in ordine. */
    private function inspectionTemplateRows(?array $ids = null)
    {
        if ($ids !== null && $ids === []) {
            return collect();
        }

        return \App\Models\InspectionTemplate::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->where('is_active', true)
            // Un modello senza domande non è compilabile: non arriva sul device
            ->whereHas('items')
            ->with('items:id,template_id,sort_order,question,answer_type,ko_creates_nc')
            ->orderBy('name')
            ->get();
    }

    /** Cambia quando cambia il blocco cataloghi: la PWA lo riscarica (§5.3). */
    private function catalogVersion(string $tenantId): string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT md5(CONCAT(
              (SELECT COALESCE(MAX(updated_at)::text, '') || COUNT(*) FROM catalog_object_types WHERE tenant_id = ?),
              (SELECT COALESCE(MAX(updated_at)::text, '') || COUNT(*) FROM custom_fields WHERE tenant_id = ?)
            )) AS v
            SQL, [$tenantId, $tenantId]);

        return $row->v;
    }
}
