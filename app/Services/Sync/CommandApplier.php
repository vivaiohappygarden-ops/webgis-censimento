<?php

namespace App\Services\Sync;

use App\Models\Asset;
use App\Models\CatalogObjectType;
use App\Models\PlantingSite;
use App\Models\Tree;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkOrder;
use App\Services\Catalog\AttributeValidator;
use App\Support\Audit;
use App\Support\Geometry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Applica un singolo comando della coda offline (OFFLINE-SYNC §4-§5).
 * Ogni comando gira nella propria transazione; l'esito è un array
 * serializzabile che il controller registra in sync_operations.
 */
class CommandApplier
{
    public const TYPES = [
        'asset.create', 'asset.update_attrs', 'asset.update_measures',
        'asset.update_geom', 'asset.change_status', 'tag.associate',
        'work_order.transition', 'work_log.add', 'issue.create', 'inspection.complete',
    ];

    /**
     * Passaggi di stato concessi dal campo a chi ha solo works.view:
     * l'operatore avvia, sospende, riprende e completa; il resto del
     * flusso (pianificazione, annullamento) resta al backoffice.
     */
    private const OPERATOR_TRANSITIONS = [
        'assigned' => ['in_progress'],
        'in_progress' => ['suspended', 'completed'],
        'suspended' => ['in_progress'],
    ];

    /** Campi dendrometrici ammessi da asset.update_measures. */
    private const MEASURE_FIELDS = [
        'genus', 'species', 'common_name', 'height_m', 'dbh_cm',
        'trunk_circumference_cm', 'trunk_count', 'crown_diameter_m',
        'crown_insertion_m', 'vegetative_state',
    ];

    /** @return array<string, mixed> */
    public function apply(array $command, User $user): array
    {
        $type = $command['type'];

        if (! in_array($type, self::TYPES, true)) {
            return $this->rejected($command, 'UNKNOWN_TYPE', "Tipo di comando sconosciuto: {$type}.");
        }

        $permission = match ($type) {
            'asset.create' => 'assets.create',
            // La regola fine (proprio ordine/squadra) è dentro l'applier
            'work_order.transition', 'work_log.add', 'issue.create', 'inspection.complete' => 'works.view',
            default => 'assets.update',
        };
        if (! $user->can($permission)) {
            return $this->rejected($command, 'FORBIDDEN', "Permesso mancante: {$permission}.");
        }

        try {
            return DB::transaction(fn () => match ($type) {
                'asset.create' => $this->applyCreate($command, $user),
                'asset.update_attrs' => $this->applyUpdate($command, $user, ['census_code', 'attributes', 'notes']),
                'asset.update_measures' => $this->applyMeasures($command, $user),
                'asset.update_geom' => $this->applyGeom($command, $user),
                'asset.change_status' => $this->applyUpdate($command, $user, ['status']),
                'tag.associate' => $this->applyTagAssociate($command, $user),
                'work_order.transition' => $this->applyWorkOrderTransition($command, $user),
                'work_log.add' => $this->applyWorkLogAdd($command, $user),
                'issue.create' => $this->applyIssueCreate($command, $user),
                'inspection.complete' => $this->applyInspectionComplete($command, $user),
            });
        } catch (ValidationException $e) {
            return $this->rejected($command, 'VALIDATION_FAILED', collect($e->errors())->flatten()->first());
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Due comandi concorrenti con lo stesso identificativo:
            // l'esito di business è la collisione, non un errore interno
            if ($type === 'issue.create') {
                return $this->rejected($command, 'ID_COLLISION', 'Esiste già una segnalazione con questo identificativo.');
            }
            if ($type === 'inspection.complete') {
                return $this->rejected($command, 'ID_COLLISION', 'Esiste già un\'ispezione con questo identificativo.');
            }
            // Race tra due device sull'associazione dello stesso tag: la transazione
            // è già annullata, si rilegge l'occupante e si risponde con l'esito
            // di business corretto invece di un errore interno
            if ($type === 'tag.associate') {
                $occupant = \App\Models\AssetTag::query()
                    ->where('tag_type', $command['payload']['tag_type'] ?? '')
                    ->where('uid', $command['payload']['uid'] ?? '')
                    ->whereIn('status', ['active', 'unassigned'])
                    ->first();
                $other = $occupant?->asset_id ? Asset::query()->withTrashed()->find($occupant->asset_id) : null;

                return $this->rejected($command, 'TAG_IN_USE',
                    'Tag già associato all\'elemento '.($other?->census_code ?? 'di un altro operatore').'.');
            }

            throw $e;
        }
    }

    private function applyCreate(array $command, User $user): array
    {
        $payload = Validator::make($command['payload'] ?? [], [
            'area_id' => ['required', 'uuid'],
            'object_type_id' => ['required', 'uuid'],
            'census_code' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'max:50'],
            'attributes' => ['sometimes', 'array'],
            'notes' => ['nullable', 'string'],
        ])->validate();

        $geom = $command['geom'] ?? null;
        if (! is_array($geom) || empty($geom['type'])) {
            return $this->rejected($command, 'VALIDATION_FAILED', 'Geometria obbligatoria per asset.create.');
        }

        if (Asset::withoutGlobalScopes()->withTrashed()->whereKey($command['entity_id'])->exists()) {
            return $this->rejected($command, 'ID_COLLISION', 'Esiste già un elemento con questo identificativo.');
        }

        $type = CatalogObjectType::query()->find($payload['object_type_id']);
        $area = \App\Models\Area::query()->find($payload['area_id']);
        if (! $type || ! $area) {
            return $this->rejected($command, 'VALIDATION_FAILED', 'Area o tipo oggetto inesistente per questa organizzazione.');
        }

        $allowed = array_map('strtoupper', $type->allowedGeometryTypes());
        if (! in_array(strtoupper($geom['type']), $allowed, true)) {
            return $this->rejected($command, 'VALIDATION_FAILED',
                "Il tipo {$type->code} richiede una geometria ".implode('/', $type->allowedGeometryTypes()).'.');
        }

        $properties = $this->validatedGeomProperties($geom);
        unset($geom['properties']);

        // Il codice censimento deve restare unico nel tenant (stessa regola del backoffice)
        if (! empty($payload['census_code'])) {
            $exists = Asset::query()->where('census_code', $payload['census_code'])->exists();
            if ($exists) {
                return $this->rejected($command, 'VALIDATION_FAILED',
                    "Codice censimento già in uso: {$payload['census_code']}.");
            }
        }

        $attributes = app(AttributeValidator::class)->validate($type, $payload['attributes'] ?? []);

        // L'UUID proposto dal device è l'ID definitivo (OFFLINE-SYNC §1.2):
        // non è mass-assignable, va impostato esplicitamente
        $asset = new Asset([
            'tenant_id' => $user->tenant_id,
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            // Se il dispositivo non propone un codice, lo assegna il server con
            // il prefisso del committente: due operatori che rientrano da
            // offline nello stesso momento non possono prendere lo stesso numero
            'census_code' => $payload['census_code'] ?? \App\Support\PortalLabels::nextCode($area->id),
            'status' => $payload['status'] ?? 'active',
            'notes' => $payload['notes'] ?? null,
            'attributes' => $attributes,
            'geom' => Geometry::toEwkb($geom),
            'survey_method' => $properties['survey_method'] ?? 'gps',
            'gps_accuracy_m' => $properties['gps_accuracy_m'] ?? null,
            'surveyed_at' => $command['client_ts'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $asset->id = $command['entity_id'];
        $asset->save();

        if ($type->requires_tree_record) {
            Tree::create(['asset_id' => $asset->id, 'tenant_id' => $asset->tenant_id]);
        }
        if ($type->is_planting_site) {
            PlantingSite::create(['asset_id' => $asset->id, 'tenant_id' => $asset->tenant_id]);
        }

        Audit::log('asset.created', $asset, ['source' => 'sync', 'device_seq' => $command['device_seq'] ?? null]);

        return $this->applied($command, $asset->fresh()->version);
    }

    /** Patch di campi dell'asset con optimistic locking. */
    private function applyUpdate(array $command, User $user, array $allowedFields): array
    {
        [$asset, $conflict] = $this->lockAndCheck($command);
        if ($conflict !== null) {
            return $conflict;
        }

        $payload = array_intersect_key($command['payload'] ?? [], array_flip($allowedFields));
        if ($payload === []) {
            return $this->rejected($command, 'VALIDATION_FAILED', 'Nessun campo applicabile nel comando.');
        }

        Validator::make($payload, [
            'census_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'attributes' => ['sometimes', 'array'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', 'max:50'],
        ])->validate();

        if (array_key_exists('attributes', $payload)) {
            $payload['attributes'] = app(AttributeValidator::class)
                ->validate($asset->objectType, $payload['attributes'] ?? []);
        }
        if (! empty($payload['census_code']) && $payload['census_code'] !== $asset->census_code) {
            $exists = Asset::query()->where('census_code', $payload['census_code'])
                ->whereKeyNot($asset->id)->exists();
            if ($exists) {
                return $this->rejected($command, 'VALIDATION_FAILED',
                    "Codice censimento già in uso: {$payload['census_code']}.");
            }
        }

        $asset->fill($payload);
        $asset->updated_by = $user->id;
        $asset->save();

        Audit::log('asset.updated', $asset, ['source' => 'sync']);

        return $this->applied($command, $asset->fresh()->version);
    }

    /** Dendrometria della scheda albero, versionata sull'asset. */
    private function applyMeasures(array $command, User $user): array
    {
        [$asset, $conflict] = $this->lockAndCheck($command);
        if ($conflict !== null) {
            return $conflict;
        }
        if (! $asset->tree) {
            return $this->rejected($command, 'VALIDATION_FAILED', 'Questo elemento non ha una scheda albero.');
        }

        $payload = array_intersect_key($command['payload'] ?? [], array_flip(self::MEASURE_FIELDS));
        if ($payload === []) {
            return $this->rejected($command, 'VALIDATION_FAILED', 'Nessuna misura applicabile nel comando.');
        }

        Validator::make($payload, [
            'genus' => ['sometimes', 'nullable', 'string', 'max:100'],
            'species' => ['sometimes', 'nullable', 'string', 'max:150'],
            'common_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'height_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:150'],
            'dbh_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2000'],
            'trunk_circumference_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:6000'],
            'trunk_count' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'crown_diameter_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'crown_insertion_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'vegetative_state' => ['sometimes', 'nullable', 'string', 'max:50'],
        ])->validate();

        $asset->tree->fill($payload);
        $treeChanged = $asset->tree->isDirty();
        $asset->tree->save();

        if ($treeChanged) {
            DB::update('UPDATE assets SET version = version + 1 WHERE id = ?', [$asset->id]);
        }

        Audit::log('asset.updated', $asset, ['source' => 'sync', 'fields' => array_keys($payload)]);

        return $this->applied($command, $asset->fresh()->version);
    }

    private function applyGeom(array $command, User $user): array
    {
        [$asset, $conflict] = $this->lockAndCheck($command);
        if ($conflict !== null) {
            return $conflict;
        }

        $geom = $command['geom'] ?? null;
        if (! is_array($geom) || empty($geom['type'])) {
            return $this->rejected($command, 'VALIDATION_FAILED', 'Geometria mancante per asset.update_geom.');
        }

        $allowed = array_map('strtoupper', $asset->objectType->allowedGeometryTypes());
        if (! in_array(strtoupper($geom['type']), $allowed, true)) {
            return $this->rejected($command, 'VALIDATION_FAILED',
                'La geometria non è compatibile con il tipo oggetto.');
        }

        $properties = $this->validatedGeomProperties($geom);
        unset($geom['properties']);

        $asset->geom = Geometry::toEwkb($geom);
        if (isset($properties['survey_method'])) {
            $asset->survey_method = $properties['survey_method'];
        }
        if (array_key_exists('gps_accuracy_m', $properties)) {
            $asset->gps_accuracy_m = $properties['gps_accuracy_m'];
        }
        $asset->updated_by = $user->id;
        $asset->save();

        Audit::log('asset.updated', $asset, ['source' => 'sync', 'fields' => ['geom']]);

        return $this->applied($command, $asset->fresh()->version);
    }

    /**
     * Blocca la riga e verifica l'optimistic locking.
     *
     * @return array{0: ?Asset, 1: ?array} asset bloccato oppure esito conflict/rejected
     */
    private function lockAndCheck(array $command): array
    {
        if (! isset($command['base_version'])) {
            return [null, $this->rejected($command, 'VALIDATION_FAILED', 'base_version obbligatoria per questo comando.')];
        }

        $asset = Asset::query()->with(['objectType', 'tree'])->lockForUpdate()->find($command['entity_id']);
        if (! $asset) {
            return [null, $this->rejected($command, 'NOT_FOUND', 'Elemento inesistente o non accessibile.')];
        }

        if ($asset->version !== (int) $command['base_version']) {
            $touched = array_keys($command['payload'] ?? []);
            $current = $asset->only(['census_code', 'attributes', 'notes', 'status']);
            if ($asset->tree) {
                $current = [...$current, ...$asset->tree->only(self::MEASURE_FIELDS)];
            }

            return [null, [
                'idempotency_key' => $command['idempotency_key'],
                'status' => 'conflict',
                'code' => 'VERSION_MISMATCH',
                'entity_id' => $command['entity_id'],
                'yours' => [
                    'base_version' => (int) $command['base_version'],
                    'payload' => $command['payload'] ?? [],
                ],
                'theirs' => [
                    'version' => $asset->version,
                    'updated_by' => $asset->updated_by,
                    'updated_at' => $asset->updated_at?->toIso8601String(),
                    'fields' => array_intersect_key($current, array_flip($touched)) ?: $current,
                ],
            ]];
        }

        return [$asset, null];
    }

    /**
     * Associazione di un tag fisico (barcode/QR/NFC) a un elemento censito.
     * Comando append-only (evento): non porta base_version e non confligge mai.
     */
    private function applyTagAssociate(array $command, User $user): array
    {
        $payload = Validator::make($command['payload'] ?? [], [
            'uid' => ['required', 'string', 'max:100'],
            'tag_type' => ['required', 'in:nfc,qr,barcode,rfid,arbotag'],
        ])->validate();

        $asset = Asset::query()->find($command['entity_id']);
        if (! $asset) {
            return $this->rejected($command, 'NOT_FOUND', 'Elemento inesistente o non accessibile.');
        }

        $existing = \App\Models\AssetTag::query()
            ->where('tag_type', $payload['tag_type'])
            ->where('uid', $payload['uid'])
            ->whereIn('status', ['active', 'unassigned'])
            ->lockForUpdate()
            ->first();

        if ($existing && $existing->asset_id === $asset->id) {
            // Stessa associazione già presente: naturalmente idempotente
            return $this->applied($command, $asset->version);
        }

        if ($existing && $existing->asset_id !== null) {
            $other = Asset::query()->withTrashed()->find($existing->asset_id);

            return $this->rejected($command, 'TAG_IN_USE',
                'Tag già associato all\'elemento '.($other?->census_code ?? $existing->asset_id).'.');
        }

        if ($existing) {
            // Tag a magazzino (unassigned): si aggancia all'elemento
            $existing->update([
                'asset_id' => $asset->id,
                'status' => 'active',
                'attached_by' => $user->id,
                'attached_at' => now(),
            ]);
            $tag = $existing;
        } else {
            $tag = \App\Models\AssetTag::create([
                'tenant_id' => $user->tenant_id,
                'asset_id' => $asset->id,
                'tag_type' => $payload['tag_type'],
                'uid' => $payload['uid'],
                'status' => 'active',
                'attached_by' => $user->id,
                'attached_at' => now(),
            ]);
        }

        Audit::log('tag.associated', $tag, ['source' => 'sync', 'asset_id' => $asset->id]);

        return $this->applied($command, $asset->version);
    }

    /**
     * Cambio di stato di un ordine di lavoro dal campo: stessa macchina a
     * stati del backoffice, con la rosa ristretta per il solo works.view.
     */
    private function applyWorkOrderTransition(array $command, User $user): array
    {
        $payload = Validator::make($command['payload'] ?? [], [
            'status' => ['required', 'in:'.implode(',', WorkOrder::STATUSES)],
        ])->validate();

        if (! isset($command['base_version'])) {
            return $this->rejected($command, 'VALIDATION_FAILED', 'base_version obbligatoria per questo comando.');
        }

        $order = WorkOrder::query()->lockForUpdate()->find($command['entity_id']);
        if (! $order) {
            return $this->rejected($command, 'NOT_FOUND', 'Ordine di lavoro inesistente o non accessibile.');
        }
        if (! $this->canActOnWorkOrder($user, $order)) {
            return $this->rejected($command, 'FORBIDDEN', 'Ordine non assegnato a te o a una tua squadra.');
        }

        if ($order->version !== (int) $command['base_version']) {
            return [
                'idempotency_key' => $command['idempotency_key'],
                'status' => 'conflict',
                'code' => 'VERSION_MISMATCH',
                'entity_id' => $command['entity_id'],
                'yours' => [
                    'base_version' => (int) $command['base_version'],
                    'payload' => $command['payload'] ?? [],
                ],
                'theirs' => [
                    'version' => $order->version,
                    'updated_by' => $order->updated_by,
                    'updated_at' => $order->updated_at?->toIso8601String(),
                    'fields' => ['status' => $order->status],
                ],
            ];
        }

        $target = $payload['status'];
        if (! $order->canTransitionTo($target)) {
            return $this->rejected($command, 'VALIDATION_FAILED',
                "Passaggio non ammesso: da '{$order->status}' a '{$target}'.");
        }
        if (! $user->can('works.manage')
            && ! in_array($target, self::OPERATOR_TRANSITIONS[$order->status] ?? [], true)) {
            return $this->rejected($command, 'FORBIDDEN', 'Questo passaggio di stato non è consentito dal campo.');
        }
        if (in_array($target, ['assigned', 'in_progress'], true)
            && $order->team_id === null && $order->assigned_to === null) {
            return $this->rejected($command, 'VALIDATION_FAILED',
                'Assegnare una squadra o un responsabile prima di questo passaggio.');
        }

        $order->status = $target;
        if ($target === 'completed') {
            // Il lavoro è stato completato quando l'operatore ha premuto il
            // pulsante in campo (client_ts), non quando il device ha ritrovato
            // la rete: altrimenti il rendiconto attribuisce il mese sbagliato.
            // Valori non plausibili (futuro, o più vecchi di 30 giorni) cadono
            // sull'ora del server (OFFLINE-SYNC §10.3)
            $completedAt = now();
            if (! empty($command['client_ts'])) {
                $claimed = \Illuminate\Support\Carbon::parse($command['client_ts'])->utc();
                if ($claimed->lte(now()->addMinutes(10)) && $claimed->gte(now()->subDays(30))) {
                    $completedAt = $claimed;
                }
            }
            $order->completed_at = $completedAt;
        }
        $order->version += 1;
        $order->updated_by = $user->id;
        $order->save();

        Audit::log('work_order.transition', $order, ['to' => $target, 'source' => 'sync']);

        return $this->applied($command, $order->version);
    }

    /**
     * Consuntivo di campo: evento append-only con id (UUID v7) scelto dal
     * device. Nessuna base_version: un consuntivo non confligge mai.
     */
    private function applyWorkLogAdd(array $command, User $user): array
    {
        $payload = Validator::make($command['payload'] ?? [], [
            'work_order_id' => ['required', 'uuid'],
            'asset_id' => ['nullable', 'uuid'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            // Il massimo rispecchia la colonna numeric(6,2): oltre 9999.99 il DB
            // andrebbe in overflow e il rifiuto diventerebbe un errore "ritentabile"
            'man_hours' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'unit' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
        ])->validate();

        $order = WorkOrder::query()->find($payload['work_order_id']);
        if (! $order) {
            return $this->rejected($command, 'NOT_FOUND', 'Ordine di lavoro inesistente o non accessibile.');
        }
        if (! $this->canActOnWorkOrder($user, $order)) {
            return $this->rejected($command, 'FORBIDDEN', 'Ordine non assegnato a te o a una tua squadra.');
        }
        if ($order->status === 'cancelled') {
            return $this->rejected($command, 'VALIDATION_FAILED', 'Ordine annullato: consuntivo non registrabile.');
        }

        if (WorkLog::withoutGlobalScopes()->withTrashed()->whereKey($command['entity_id'])->exists()) {
            return $this->rejected($command, 'ID_COLLISION', 'Esiste già un consuntivo con questo identificativo.');
        }
        if (! empty($payload['asset_id']) && ! Asset::query()->withTrashed()->whereKey($payload['asset_id'])->exists()) {
            return $this->rejected($command, 'VALIDATION_FAILED', 'Elemento inesistente per questa organizzazione.');
        }

        $log = WorkLog::create([
            'id' => $command['entity_id'],
            'tenant_id' => $user->tenant_id,
            'work_order_id' => $order->id,
            'asset_id' => $payload['asset_id'] ?? null,
            'team_id' => $order->team_id,
            'operator_id' => $user->id,
            'started_at' => \Illuminate\Support\Carbon::parse($payload['started_at'])->utc(),
            'ended_at' => isset($payload['ended_at']) ? \Illuminate\Support\Carbon::parse($payload['ended_at'])->utc() : null,
            'man_hours' => $payload['man_hours'] ?? null,
            'quantity' => $payload['quantity'] ?? null,
            'unit' => $payload['unit'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]);

        Audit::log('work_log.created', $log, ['source' => 'sync', 'work_order_id' => $order->id]);

        return $this->applied($command, 1);
    }

    /**
     * Segnalazione aperta dal campo: evento append-only con id (UUID v7)
     * scelto dal device; il numero SEG lo assegna il server all'arrivo.
     */
    private function applyIssueCreate(array $command, User $user): array
    {
        $payload = Validator::make($command['payload'] ?? [], [
            'description' => ['required', 'string', 'max:4000'],
            'severity' => ['sometimes', 'in:low,medium,high,critical'],
            'asset_id' => ['nullable', 'uuid'],
            'area_id' => ['nullable', 'uuid'],
        ])->validate();

        if (\App\Models\Issue::withoutGlobalScopes()->withTrashed()->whereKey($command['entity_id'])->exists()) {
            return $this->rejected($command, 'ID_COLLISION', 'Esiste già una segnalazione con questo identificativo.');
        }
        // withTrashed: se il backoffice ha eliminato l'elemento mentre il device
        // era offline, l'osservazione fatta sul campo non deve andare persa
        if (! empty($payload['asset_id']) && ! Asset::query()->withTrashed()->whereKey($payload['asset_id'])->exists()) {
            return $this->rejected($command, 'VALIDATION_FAILED', 'Elemento inesistente per questa organizzazione.');
        }
        if (! empty($payload['area_id']) && ! \App\Models\Area::query()->withTrashed()->whereKey($payload['area_id'])->exists()) {
            return $this->rejected($command, 'VALIDATION_FAILED', 'Area inesistente per questa organizzazione.');
        }

        $issue = new \App\Models\Issue([
            'tenant_id' => $user->tenant_id,
            'code' => \App\Models\Issue::nextCode($user->tenant_id),
            'status' => 'open',
            'severity' => $payload['severity'] ?? 'medium',
            'reporter_type' => 'internal',
            'reporter_user_id' => $user->id,
            'channel' => 'pwa',
            'asset_id' => $payload['asset_id'] ?? null,
            'area_id' => $payload['area_id'] ?? null,
            'description' => $payload['description'],
        ]);
        $issue->id = $command['entity_id'];
        // La segnalazione è di quando l'operatore l'ha scritta in campo, non
        // di quando il device ha ritrovato la rete (stessa finestra di
        // plausibilità della chiusura lavori)
        if (! empty($command['client_ts'])) {
            $claimed = \Illuminate\Support\Carbon::parse($command['client_ts'])->utc();
            if ($claimed->lte(now()->addMinutes(10)) && $claimed->gte(now()->subDays(30))) {
                $issue->created_at = $claimed;
            }
        }
        // Le scadenze SLA decorrono da quando è stata scritta in campo
        $issue->sla_due_at = \App\Support\IssueSla::resolveDueAt($issue->created_at ?? now(), $issue->severity);
        $issue->taken_charge_due_at = \App\Support\IssueSla::takeChargeDueAt($issue->created_at ?? now(), $issue->severity);
        $issue->save();

        Audit::log('issue.created', $issue, ['source' => 'sync', 'code' => $issue->code]);

        return $this->applied($command, 1);
    }

    /**
     * Ispezione compilata in campo: stesso motore del backoffice
     * (validazioni, esito, non conformità), id scelto dal device.
     */
    private function applyInspectionComplete(array $command, User $user): array
    {
        $payload = Validator::make($command['payload'] ?? [], [
            'template_id' => ['required', 'uuid'],
            'asset_id' => ['nullable', 'uuid'],
            'area_id' => ['nullable', 'uuid'],
            'answers' => ['required', 'array'],
        ])->validate();

        if (\App\Models\Inspection::withoutGlobalScopes()->withTrashed()->whereKey($command['entity_id'])->exists()) {
            return $this->rejected($command, 'ID_COLLISION', 'Esiste già un\'ispezione con questo identificativo.');
        }

        // L'ispezione è di quando l'operatore l'ha chiusa in campo, non di
        // quando il device ha ritrovato la rete (finestra di plausibilità)
        $completedAt = now();
        if (! empty($command['client_ts'])) {
            $claimed = \Illuminate\Support\Carbon::parse($command['client_ts'])->utc();
            if ($claimed->lte(now()->addMinutes(10)) && $claimed->gte(now()->subDays(30))) {
                $completedAt = $claimed;
            }
        }

        $inspection = app(\App\Services\Inspections\InspectionRunner::class)
            ->run($user, $payload, forcedId: $command['entity_id'], completedAt: $completedAt);

        return [
            ...$this->applied($command, 1),
            'outcome' => $inspection->outcome,
        ];
    }

    /** Chi gestisce i lavori agisce su tutto; l'operatore solo sul suo. */
    private function canActOnWorkOrder(User $user, WorkOrder $order): bool
    {
        if ($user->can('works.manage')) {
            return true;
        }
        if ($order->assigned_to === $user->id) {
            return true;
        }

        return $order->team_id !== null && DB::table('team_members')
            ->where('team_id', $order->team_id)
            ->where('user_id', $user->id)
            ->whereNull('left_on')
            ->exists();
    }

    /** Le proprietà del rilievo vanno validate come il resto: un valore fuori
     *  lista finirebbe sul CHECK del DB come errore interno, bloccando la coda. */
    private function validatedGeomProperties(array $geom): array
    {
        return Validator::make($geom['properties'] ?? [], [
            'survey_method' => ['sometimes', 'nullable',
                'in:gps,gps_rtk,digitized,cad_import,shapefile_import,manual_map,estimated'],
            'gps_accuracy_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
        ])->validate();
    }

    private function applied(array $command, int $version): array
    {
        return [
            'idempotency_key' => $command['idempotency_key'],
            'status' => 'applied',
            'entity_id' => $command['entity_id'],
            'version' => $version,
        ];
    }

    private function rejected(array $command, string $code, ?string $message): array
    {
        return [
            'idempotency_key' => $command['idempotency_key'],
            'status' => 'rejected',
            'code' => $code,
            'entity_id' => $command['entity_id'] ?? null,
            'message' => $message ?? 'Comando non applicabile.',
        ];
    }
}
