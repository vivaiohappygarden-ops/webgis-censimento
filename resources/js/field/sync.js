import axios from 'axios';
import { uuidv7 } from './uuidv7';

/**
 * Gestore di sincronizzazione della PWA operatore (OFFLINE-SYNC §4-§5, v1).
 * Coda FIFO per device_seq, push batch idempotente, pull delta a cursore.
 */
export class SyncManager {
    constructor(db) {
        this.db = db;
        this.syncing = false;
        this.listeners = new Set();
    }

    onChange(fn) {
        this.listeners.add(fn);
        return () => this.listeners.delete(fn);
    }

    async notify() {
        const pending = await this.db.sync_queue.where('status').anyOf('PENDING', 'INFLIGHT').count();
        const attention = await this.db.sync_queue.where('status').anyOf('CONFLICT', 'NEEDS_ATTENTION').count();
        const state = { pending, attention, syncing: this.syncing, online: navigator.onLine };
        this.listeners.forEach((fn) => fn(state));
        return state;
    }

    async log(level, message) {
        await this.db.sync_log.add({ ts: new Date().toISOString(), level, message });
        const count = await this.db.sync_log.count();
        if (count > 2000) {
            const first = await this.db.sync_log.orderBy('seq').first();
            await this.db.sync_log.where('seq').belowOrEqual(first.seq + (count - 2000)).delete();
        }
    }

    async deviceId() {
        let row = await this.db.meta.get('device_id');
        if (! row) {
            row = { key: 'device_id', value: `dev-${uuidv7()}` };
            await this.db.meta.put(row);
        }
        return row.value;
    }

    async nextDeviceSeq() {
        return this.db.transaction('rw', this.db.meta, async () => {
            const row = (await this.db.meta.get('device_seq')) ?? { key: 'device_seq', value: 0 };
            row.value += 1;
            await this.db.meta.put(row);
            return row.value;
        });
    }

    /** Scarica il working set e azzera la replica locale (non la coda). */
    async bootstrap() {
        const { data } = await axios.get('/api/v1/sync/bootstrap');

        await this.db.transaction('rw',
            [this.db.meta, this.db.areas, this.db.assets, this.db.trees, this.db.catalog_types, this.db.custom_fields, this.db.sync_queue],
            async () => {
                // Il lavoro locale non ancora sincronizzato non si tocca (P1):
                // le righe ottimistiche sopravvivono al ri-scarico del working set
                const dirtyRows = await this.db.assets.filter((a) => a.dirty === true).toArray();

                await Promise.all([
                    this.db.areas.clear(), this.db.assets.clear(), this.db.trees.clear(),
                    this.db.catalog_types.clear(), this.db.custom_fields.clear(),
                ]);
                await this.db.areas.bulkPut(data.areas);
                await this.db.assets.bulkPut(data.assets.map((a) => ({ ...a, dirty: false })));
                await this.db.trees.bulkPut(data.assets.filter((a) => a.tree).map((a) => a.tree));
                await this.db.assets.bulkPut(dirtyRows);
                await this.db.catalog_types.bulkPut(data.catalog.object_types);
                await this.db.custom_fields.bulkPut(data.custom_fields);
                await this.db.meta.put({ key: 'cursor', value: data.cursor });
                await this.db.meta.put({ key: 'catalog_version', value: data.catalog_version });
                await this.db.meta.put({ key: 'bootstrapped_at', value: data.server_time });
            });

        await this.log('info', `Dati scaricati: ${data.areas.length} aree, ${data.assets.length} elementi, ${data.catalog.object_types.length} tipi.`);
        return this.notify();
    }

    /** Accoda un comando e applica ottimisticamente la modifica locale. */
    async enqueueAssetCreate({ areaId, objectTypeId, censusCode, notes, geometry, gpsAccuracy, surveyMethod = 'gps' }) {
        const entityId = uuidv7();
        const command = {
            idempotency_key: crypto.randomUUID(),
            device_seq: await this.nextDeviceSeq(),
            type: 'asset.create',
            entity_id: entityId,
            payload: {
                area_id: areaId,
                object_type_id: objectTypeId,
                census_code: censusCode || null,
                notes: notes || null,
            },
            geom: {
                ...geometry,
                properties: { survey_method: surveyMethod, gps_accuracy_m: gpsAccuracy ?? null },
            },
            client_ts: new Date().toISOString(),
            status: 'PENDING',
            attempts: 0,
            last_error: null,
        };

        const type = await this.db.catalog_types.get(objectTypeId);
        await this.db.transaction('rw', [this.db.sync_queue, this.db.assets], async () => {
            await this.db.sync_queue.add(command);
            // Apply ottimistico: l'operatore vede subito il suo lavoro (dirty finché non sincronizzato)
            await this.db.assets.put({
                id: entityId,
                area_id: areaId,
                object_type_id: objectTypeId,
                census_code: censusCode || null,
                notes: notes || null,
                status: 'active',
                version: 0,
                geom_geojson: geometry,
                object_type: type ? { code: type.code, name: type.name } : null,
                updated_at: new Date().toISOString(),
                dirty: true,
            });
        });

        await this.log('info', `In coda: nuovo elemento ${censusCode || entityId.slice(0, 8)}.`);
        await this.notify();
        return entityId;
    }

    /** Push della coda + pull delta. Ritorna lo stato finale. */
    async syncNow() {
        if (this.syncing) return this.notify();
        this.syncing = true;
        await this.notify();

        try {
            await this.pushQueue();
            await this.pullChanges();
            await this.log('info', 'Sincronizzazione completata.');
        } catch (err) {
            await this.log('error', `Sincronizzazione interrotta: ${err.response?.status ?? err.message}`);
        } finally {
            this.syncing = false;
        }

        return this.notify();
    }

    async pushQueue() {
        // Recupero dei comandi rimasti "in volo" (app chiusa durante un invio):
        // il replay lato server risponde duplicate, quindi reinviare è sempre sicuro
        await this.db.sync_queue.where('status').equals('INFLIGHT').modify({ status: 'PENDING' });

        const pending = await this.db.sync_queue
            .where('[status+device_seq]').between(['PENDING', -Infinity], ['PENDING', Infinity])
            .limit(200).toArray();
        if (! pending.length) return;

        const batch = {
            batch_id: crypto.randomUUID(),
            device_id: await this.deviceId(),
            schema: 1,
            app_version: '0.3.0',
            client_time: new Date().toISOString(),
            commands: pending.map(({ status, attempts, last_error, ...cmd }) => cmd),
        };

        await this.db.sync_queue.where('idempotency_key')
            .anyOf(pending.map((c) => c.idempotency_key)).modify({ status: 'INFLIGHT' });

        let response;
        try {
            response = await axios.post('/api/v1/sync/batch', batch);
        } catch (err) {
            // Errore di envelope o rete: i comandi tornano PENDING (retry al prossimo giro)
            const status = err.response?.status;
            const hint = status === 401 || status === 419
                ? 'Sessione scaduta: accedi di nuovo per inviare il lavoro.'
                : String(status ?? err.message);
            await this.db.sync_queue.where('idempotency_key')
                .anyOf(pending.map((c) => c.idempotency_key))
                .modify((c) => { c.status = 'PENDING'; c.attempts += 1; c.last_error = hint; });
            throw err;
        }

        for (const result of response.data.results) {
            const outcome = result.status === 'duplicate' ? (result.original?.status ?? 'applied') : result.status;
            if (outcome === 'applied') {
                await this.db.sync_queue.delete(result.idempotency_key);
            } else if (outcome === 'error') {
                // Errore interno lato server: la chiave non è stata consumata, si ritenta
                await this.db.sync_queue.update(result.idempotency_key, {
                    status: 'PENDING',
                    last_error: result.message ?? 'errore temporaneo del server',
                });
            } else if (outcome === 'conflict') {
                await this.db.sync_queue.update(result.idempotency_key, { status: 'CONFLICT' });
                await this.db.conflicts.put({
                    idempotency_key: result.idempotency_key,
                    entity_id: result.entity_id,
                    yours: result.yours ?? result.original?.yours ?? null,
                    theirs: result.theirs ?? result.original?.theirs ?? null,
                    resolved: 0,
                });
                await this.log('warn', `Conflitto sull'elemento ${result.entity_id}: qualcun altro l'ha modificato.`);
            } else {
                await this.db.sync_queue.update(result.idempotency_key, {
                    status: 'NEEDS_ATTENTION',
                    last_error: result.message ?? result.code ?? (result.original?.message ?? 'rifiutato'),
                });
                await this.log('warn', `Comando rifiutato: ${result.message ?? result.code}.`);
            }
        }
    }

    async pullChanges() {
        let hasMore = true;
        while (hasMore) {
            const cursorRow = await this.db.meta.get('cursor');
            const cursor = cursorRow?.value ?? 0;

            const dirtyIds = new Set(
                (await this.db.sync_queue.toArray()).map((c) => c.entity_id),
            );

            // Righe saltate nei pull precedenti perché localmente dirty: il cursore
            // le ha ormai superate, quindi si richiedono per id finché non rientrano
            const deferredRow = await this.db.meta.get('deferred_ids');
            const deferred = new Set(deferredRow?.value ?? []);
            const askIds = [...deferred].filter((id) => ! dirtyIds.has(id)).slice(0, 200);

            const { data } = await axios.get('/api/v1/sync/changes', {
                params: { cursor, ...(askIds.length ? { ids: askIds } : {}) },
            });

            await this.db.transaction('rw', [this.db.meta, this.db.areas, this.db.assets, this.db.trees], async () => {
                for (const change of data.changes) {
                    if (change.table === 'areas') {
                        if (change.op === 'delete') await this.db.areas.delete(change.id);
                        else await this.db.areas.put(change.row);
                    } else if (change.table === 'assets') {
                        const id = change.op === 'delete' ? change.id : change.row.id;
                        // Mai sovrascrivere un record con modifiche locali pendenti (§3.3):
                        // lo si segna come rimasto indietro e lo si riprenderà per id
                        if (dirtyIds.has(id)) {
                            deferred.add(id);
                            continue;
                        }
                        if (change.op === 'delete') {
                            await this.db.assets.delete(id);
                            await this.db.trees.delete(id);
                        } else {
                            await this.db.assets.put({ ...change.row, dirty: false });
                            if (change.row.tree) await this.db.trees.put(change.row.tree);
                        }
                        deferred.delete(id);
                    }
                }
                await this.db.meta.put({ key: 'cursor', value: data.cursor });
                await this.db.meta.put({ key: 'deferred_ids', value: [...deferred] });
            });

            hasMore = data.has_more;
        }
    }

    /** Scarto esplicito di un comando in conflitto o rifiutato (P1: mai in silenzio). */
    async discard(idempotencyKey) {
        const cmd = await this.db.sync_queue.get(idempotencyKey);
        if (! cmd) return;

        await this.db.sync_queue.delete(idempotencyKey);
        await this.db.conflicts.delete(idempotencyKey);

        // La verità del server per questa entità va ripresa al prossimo pull
        const deferredRow = await this.db.meta.get('deferred_ids');
        const deferred = new Set(deferredRow?.value ?? []);
        if (cmd.entity_id) deferred.add(cmd.entity_id);
        await this.db.meta.put({ key: 'deferred_ids', value: [...deferred] });

        // Una creazione mai arrivata al server non deve restare come riga fantasma
        if (cmd.type === 'asset.create') {
            await this.db.assets.delete(cmd.entity_id);
            await this.db.trees.delete(cmd.entity_id);
        }

        await this.log('warn', `Operazione #${cmd.device_seq} (${cmd.type}) scartata dall'operatore.`);
        await this.notify();
    }

    /** Rimette in coda un comando rifiutato dopo la correzione. */
    async retry(idempotencyKey) {
        await this.db.sync_queue.update(idempotencyKey, { status: 'PENDING', last_error: null });
        await this.log('info', 'Operazione rimessa in coda per un nuovo invio.');
        await this.notify();
    }
}
