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
        const photos = await this.db.photo_uploads.where('status').anyOf('queued', 'uploading').count();
        const photosFailed = await this.db.photo_uploads.where('status').equals('failed').count();
        const state = { pending, attention, photos, photosFailed, syncing: this.syncing, online: navigator.onLine };
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
            [this.db.meta, this.db.areas, this.db.assets, this.db.trees, this.db.asset_tags, this.db.catalog_types, this.db.custom_fields, this.db.sync_queue, this.db.work_orders, this.db.inspection_templates],
            async () => {
                // Il lavoro locale non ancora sincronizzato non si tocca (P1):
                // le righe ottimistiche sopravvivono al ri-scarico del working set
                const dirtyRows = await this.db.assets.filter((a) => a.dirty === true).toArray();
                const dirtyIds = new Set(dirtyRows.map((a) => a.id));
                const localTags = await this.db.asset_tags
                    .filter((t) => dirtyIds.has(t.asset_id)).toArray();
                const dirtyOrders = await this.db.work_orders.filter((w) => w.dirty === true).toArray();
                const startedAtById = new Map((await this.db.work_orders.toArray())
                    .filter((w) => w.field_started_at)
                    .map((w) => [w.id, w.field_started_at]));

                await Promise.all([
                    this.db.areas.clear(), this.db.assets.clear(), this.db.trees.clear(),
                    this.db.asset_tags.clear(), this.db.catalog_types.clear(), this.db.custom_fields.clear(),
                    this.db.work_orders.clear(), this.db.inspection_templates.clear(),
                ]);
                await this.db.areas.bulkPut(data.areas);
                await this.db.assets.bulkPut(data.assets.map((a) => ({ ...a, dirty: false })));
                await this.db.trees.bulkPut(data.assets.filter((a) => a.tree).map((a) => a.tree));
                await this.db.asset_tags.bulkPut(data.assets.flatMap((a) => a.tags ?? []));
                await this.db.assets.bulkPut(dirtyRows);
                await this.db.asset_tags.bulkPut(localTags);
                await this.db.work_orders.bulkPut((data.work_orders ?? []).map((w) => ({
                    ...w,
                    dirty: false,
                    ...(startedAtById.has(w.id) ? { field_started_at: startedAtById.get(w.id) } : {}),
                })));
                await this.db.work_orders.bulkPut(dirtyOrders);
                await this.db.inspection_templates.bulkPut(data.inspection_templates ?? []);
                await this.db.catalog_types.bulkPut(data.catalog.object_types);
                await this.db.custom_fields.bulkPut(data.custom_fields);
                await this.db.meta.put({ key: 'cursor', value: data.cursor });
                await this.db.meta.put({ key: 'catalog_version', value: data.catalog_version });
                await this.db.meta.put({ key: 'bootstrapped_at', value: data.server_time });
            });

        await this.log('info', `Dati scaricati: ${data.areas.length} aree, ${data.assets.length} elementi, ${(data.work_orders ?? []).length} lavori.`);
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
        await this.db.transaction('rw', [this.db.sync_queue, this.db.assets, this.db.trees], async () => {
            await this.db.sync_queue.add(command);
            // Apply ottimistico: l'operatore vede subito il suo lavoro (dirty finché non
            // sincronizzato). version 1 = la versione che la riga avrà sul server alla
            // creazione, così i comandi successivi portano una base_version corretta
            await this.db.assets.put({
                id: entityId,
                area_id: areaId,
                object_type_id: objectTypeId,
                census_code: censusCode || null,
                notes: notes || null,
                status: 'active',
                version: 1,
                geom_geojson: geometry,
                object_type: type ? { code: type.code, name: type.name } : null,
                updated_at: new Date().toISOString(),
                dirty: true,
            });
            // Un albero censito offline deve essere misurabile subito: la riga
            // della scheda albero nasce insieme all'elemento, come sul server
            if (type?.requires_tree_record) {
                await this.db.trees.put({ asset_id: entityId });
            }
        });

        await this.log('info', `In coda: nuovo elemento ${censusCode || entityId.slice(0, 8)}.`);
        await this.notify();
        return entityId;
    }

    /** Misure dendrometriche in campo (con optimistic locking sulla versione locale). */
    async enqueueMeasures({ assetId, measures }) {
        await this.db.transaction('rw', [this.db.sync_queue, this.db.assets, this.db.trees, this.db.meta], async () => {
            const asset = await this.db.assets.get(assetId);
            if (! asset) throw new Error('Elemento non presente sul dispositivo.');

            await this.db.sync_queue.add({
                idempotency_key: crypto.randomUUID(),
                device_seq: await this.nextDeviceSeq(),
                type: 'asset.update_measures',
                entity_id: assetId,
                base_version: asset.version,
                payload: measures,
                client_ts: new Date().toISOString(),
                status: 'PENDING',
                attempts: 0,
                last_error: null,
            });

            const tree = (await this.db.trees.get(assetId)) ?? { asset_id: assetId };
            await this.db.trees.put({ ...tree, ...measures });
            // La versione locale avanza come farà quella del server: due salvataggi
            // in sequenza dallo stesso device NON sono un conflitto
            await this.db.assets.put({ ...asset, version: asset.version + 1, dirty: true });
        });

        await this.log('info', 'In coda: misure aggiornate.');
        await this.notify();
    }

    /** Associazione tag fisico (append-only: non confligge mai). */
    async enqueueTagAssociate({ assetId, uid, tagType }) {
        const command = {
            idempotency_key: crypto.randomUUID(),
            device_seq: await this.nextDeviceSeq(),
            type: 'tag.associate',
            entity_id: assetId,
            payload: { uid, tag_type: tagType },
            client_ts: new Date().toISOString(),
            status: 'PENDING',
            attempts: 0,
            last_error: null,
        };

        await this.db.transaction('rw', [this.db.sync_queue, this.db.assets, this.db.asset_tags], async () => {
            await this.db.sync_queue.add(command);
            // Mai due righe locali per lo stesso tag: la riga ottimistica
            // sostituisce eventuali copie precedenti con lo stesso tipo+codice
            await this.db.asset_tags.where('[tag_type+uid]').equals([tagType, uid]).delete();
            await this.db.asset_tags.put({
                id: uuidv7(),
                asset_id: assetId,
                tag_type: tagType,
                uid,
                status: 'active',
            });
            const asset = await this.db.assets.get(assetId);
            if (asset) await this.db.assets.put({ ...asset, dirty: true });
        });

        await this.log('info', `In coda: tag ${tagType.toUpperCase()} ${uid} associato.`);
        await this.notify();
    }

    /** Cambio di stato di un ordine di lavoro dal campo (offline-first). */
    async enqueueWoTransition({ workOrderId, status }) {
        await this.db.transaction('rw', [this.db.sync_queue, this.db.work_orders, this.db.meta], async () => {
            const order = await this.db.work_orders.get(workOrderId);
            if (! order) throw new Error('Ordine non presente sul dispositivo.');

            await this.db.sync_queue.add({
                idempotency_key: crypto.randomUUID(),
                device_seq: await this.nextDeviceSeq(),
                type: 'work_order.transition',
                entity_id: workOrderId,
                base_version: order.version,
                payload: { status },
                client_ts: new Date().toISOString(),
                status: 'PENDING',
                attempts: 0,
                last_error: null,
            });

            // Stato e versione locali avanzano come sul server: i passaggi
            // successivi dallo stesso device non sono mai un conflitto
            await this.db.work_orders.put({ ...order, status, version: order.version + 1, dirty: true });
        });

        await this.log('info', `In coda: ordine in stato "${status}".`);
        await this.notify();
    }

    /**
     * Consuntivo di campo + chiusura del lavoro in un'unica transazione
     * locale: o entrambi i comandi entrano in coda o nessuno dei due
     * (mai un consuntivo orfano, mai un doppione da un nuovo tentativo).
     */
    async enqueueConsuntivoAndComplete({ workOrderId, startedAt, endedAt, manHours = null, quantity = null, unit = null, notes = null }) {
        const logId = uuidv7();
        await this.db.transaction('rw', [this.db.sync_queue, this.db.work_orders, this.db.meta], async () => {
            const order = await this.db.work_orders.get(workOrderId);
            if (! order) {
                const err = new Error('Ordine non presente sul dispositivo.');
                err.code = 'ORDER_GONE';
                throw err;
            }

            // Prima il consuntivo, poi la chiusura: la coda è FIFO e il server
            // riceve il lavoro svolto mentre l'ordine è ancora "in corso"
            await this.db.sync_queue.add({
                idempotency_key: crypto.randomUUID(),
                device_seq: await this.nextDeviceSeq(),
                type: 'work_log.add',
                entity_id: logId,
                payload: {
                    work_order_id: workOrderId,
                    asset_id: null,
                    started_at: startedAt,
                    ended_at: endedAt,
                    man_hours: manHours,
                    quantity,
                    unit,
                    notes,
                },
                client_ts: new Date().toISOString(),
                status: 'PENDING',
                attempts: 0,
                last_error: null,
            });
            await this.db.sync_queue.add({
                idempotency_key: crypto.randomUUID(),
                device_seq: await this.nextDeviceSeq(),
                type: 'work_order.transition',
                entity_id: workOrderId,
                base_version: order.version,
                payload: { status: 'completed' },
                client_ts: new Date().toISOString(),
                status: 'PENDING',
                attempts: 0,
                last_error: null,
            });
            await this.db.work_orders.put({ ...order, status: 'completed', version: order.version + 1, dirty: true });
        });

        await this.log('info', 'In coda: consuntivo e chiusura del lavoro.');
        await this.notify();
        return logId;
    }

    /** Ispezione compilata in campo (append-only): esito e non conformità
     *  li calcola il server all'arrivo, con lo stesso motore del backoffice. */
    async enqueueInspectionComplete({ templateId, assetId = null, areaId = null, answers }) {
        const inspectionId = uuidv7();
        await this.db.sync_queue.add({
            idempotency_key: crypto.randomUUID(),
            device_seq: await this.nextDeviceSeq(),
            type: 'inspection.complete',
            entity_id: inspectionId,
            payload: {
                template_id: templateId,
                asset_id: assetId,
                area_id: areaId,
                answers,
            },
            client_ts: new Date().toISOString(),
            status: 'PENDING',
            attempts: 0,
            last_error: null,
        });

        await this.log('info', 'In coda: ispezione compilata in campo.');
        await this.notify();
        return inspectionId;
    }

    /** Segnalazione dal campo (append-only): il numero SEG lo assegna il server. */
    async enqueueIssueCreate({ description, severity = 'medium', assetId = null, areaId = null }) {
        const issueId = uuidv7();
        await this.db.sync_queue.add({
            idempotency_key: crypto.randomUUID(),
            device_seq: await this.nextDeviceSeq(),
            type: 'issue.create',
            entity_id: issueId,
            payload: {
                description,
                severity,
                asset_id: assetId,
                area_id: areaId,
            },
            client_ts: new Date().toISOString(),
            status: 'PENDING',
            attempts: 0,
            last_error: null,
        });

        await this.log('info', 'In coda: segnalazione dal campo.');
        await this.notify();
        return issueId;
    }

    /** Ricerca locale di un tag per uid; con tagType preferisce la corrispondenza esatta. */
    async findByTag(uid, tagType = null) {
        const tags = await this.db.asset_tags.where('uid').equals(uid).toArray();
        if (! tags.length) return null;
        const tag = (tagType && tags.find((t) => t.tag_type === tagType)) ?? tags[0];
        const asset = await this.db.assets.get(tag.asset_id);
        return asset ? { tag, asset } : null;
    }

    /** Push della coda + foto + pull delta. Ritorna lo stato finale. */
    async syncNow() {
        if (this.syncing) return this.notify();
        this.syncing = true;
        await this.notify();

        try {
            await this.pushQueue();
            await this.uploadPhotos();
            await this.pullChanges();
            await this.log('info', 'Sincronizzazione completata.');
        } catch (err) {
            await this.log('error', `Sincronizzazione interrotta: ${err.response?.status ?? err.message}`);
        } finally {
            this.syncing = false;
        }

        return this.notify();
    }

    /** Mette in coda una foto compressa scattata in campo.
     *  La ricodifica canvas elimina gli EXIF: data di scatto e posizione
     *  GPS si salvano qui e viaggiano come campi espliciti (§7.1). */
    async enqueuePhoto({ assetId, blob, category = 'census', takenAt = null, position = null }) {
        const photoId = uuidv7();
        await this.db.transaction('rw', [this.db.photo_blobs, this.db.photo_uploads], async () => {
            await this.db.photo_blobs.put({ photo_id: photoId, blob });
            await this.db.photo_uploads.put({
                photo_id: photoId,
                asset_id: assetId,
                category,
                status: 'queued',
                attempts: 0,
                taken_at: takenAt ?? new Date().toISOString(),
                lat: position?.lat ?? null,
                lon: position?.lon ?? null,
                gps_accuracy_m: position?.accuracy ?? null,
                created_at: new Date().toISOString(),
            });
        });
        await this.log('info', 'Foto in coda di invio.');
        await this.notify();
        return photoId;
    }

    /** Rimette in coda una foto rifiutata. */
    async retryPhoto(photoId) {
        await this.db.photo_uploads.update(photoId, { status: 'queued', last_error: null });
        await this.log('info', 'Foto rimessa in coda di invio.');
        await this.notify();
    }

    /** Scarto esplicito di una foto rifiutata: elimina anche il file locale. */
    async discardPhoto(photoId) {
        await this.db.transaction('rw', [this.db.photo_blobs, this.db.photo_uploads], async () => {
            await this.db.photo_blobs.delete(photoId);
            await this.db.photo_uploads.delete(photoId);
        });
        await this.log('warn', 'Foto scartata dall\'operatore.');
        await this.notify();
    }

    /** Invia le foto in coda, una alla volta (replay-safe via client_id). */
    async uploadPhotos() {
        // Recupero di eventuali upload rimasti "in corso" dopo una chiusura
        await this.db.photo_uploads.where('status').equals('uploading').modify({ status: 'queued' });

        const queued = await this.db.photo_uploads.where('status').equals('queued').toArray();
        for (const upload of queued) {
            // L'elemento deve arrivare al server prima delle sue foto: se ha ancora
            // comandi in coda (es. censimento respinto o oltre il limite di batch)
            // la foto aspetta il prossimo giro invece di morire su un 404 transitorio
            const pendingCommands = await this.db.sync_queue.where('entity_id').equals(upload.asset_id).count();
            if (pendingCommands > 0) continue;

            const blobRow = await this.db.photo_blobs.get(upload.photo_id);
            if (! blobRow) {
                await this.db.photo_uploads.delete(upload.photo_id);
                continue;
            }

            await this.db.photo_uploads.update(upload.photo_id, { status: 'uploading' });
            try {
                const form = new FormData();
                form.append('photo', blobRow.blob, `${upload.photo_id}.jpg`);
                form.append('category', upload.category ?? 'census');
                form.append('client_id', upload.photo_id);
                if (upload.taken_at) form.append('taken_at', upload.taken_at);
                if (upload.lat != null && upload.lon != null) {
                    form.append('lat', upload.lat);
                    form.append('lon', upload.lon);
                    if (upload.gps_accuracy_m != null) form.append('gps_accuracy_m', upload.gps_accuracy_m);
                }
                await axios.post(`/api/v1/assets/${upload.asset_id}/photos`, form);

                await this.db.transaction('rw', [this.db.photo_blobs, this.db.photo_uploads], async () => {
                    await this.db.photo_blobs.delete(upload.photo_id);
                    await this.db.photo_uploads.delete(upload.photo_id);
                });
                await this.log('info', 'Foto inviata.');
            } catch (err) {
                const status = err.response?.status;
                if (status === 404 || status === 422) {
                    // Non ritentabile (elemento eliminato, file rifiutato): presa in carico esplicita
                    await this.db.photo_uploads.update(upload.photo_id, {
                        status: 'failed',
                        last_error: err.response?.data?.message ?? `errore ${status}`,
                    });
                    await this.log('warn', `Foto rifiutata dal server (${status}).`);
                } else {
                    // Rete o server: torna in coda per il prossimo giro
                    await this.db.photo_uploads.update(upload.photo_id, {
                        status: 'queued',
                        attempts: (upload.attempts ?? 0) + 1,
                    });
                    throw err;
                }
            }
        }
        await this.notify();
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

        const byKey = new Map(pending.map((c) => [c.idempotency_key, c]));

        for (const result of response.data.results) {
            const outcome = result.status === 'duplicate' ? (result.original?.status ?? 'applied') : result.status;
            if (outcome === 'applied') {
                await this.db.sync_queue.delete(result.idempotency_key);
                // Ultimo comando applicato per l'entità: si riallinea la versione
                // e si toglie il flag "da inviare" anche quando il server non ha
                // scritto nulla (esiti idempotenti senza riga di change log)
                const entityId = result.entity_id ?? result.original?.entity_id;
                const version = result.version ?? result.original?.version;
                if (entityId) {
                    const remaining = await this.db.sync_queue.where('entity_id').equals(entityId).count();
                    if (remaining === 0) {
                        const table = byKey.get(result.idempotency_key)?.type === 'work_order.transition'
                            ? this.db.work_orders
                            : this.db.assets;
                        const row = await table.get(entityId);
                        if (row) {
                            await table.put({
                                ...row,
                                dirty: false,
                                version: typeof version === 'number' ? Math.max(version, row.version) : row.version,
                            });
                        }
                    }
                }
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

            await this.db.transaction('rw', [this.db.meta, this.db.areas, this.db.assets, this.db.trees, this.db.asset_tags, this.db.work_orders, this.db.inspection_templates], async () => {
                for (const change of data.changes) {
                    if (change.table === 'areas') {
                        if (change.op === 'delete') await this.db.areas.delete(change.id);
                        else await this.db.areas.put(change.row);
                    } else if (change.table === 'inspection_templates') {
                        // I modelli non hanno stato locale: la verità del server basta
                        if (change.op === 'delete') await this.db.inspection_templates.delete(change.id);
                        else await this.db.inspection_templates.put(change.row);
                    } else if (change.table === 'work_orders') {
                        const id = change.op === 'delete' ? change.id : change.row.id;
                        // Come per gli elementi: un ordine con comandi locali in coda
                        // non si sovrascrive, lo si riprende per id al giro successivo
                        if (dirtyIds.has(id)) {
                            deferred.add(id);
                            continue;
                        }
                        if (change.op === 'delete') {
                            await this.db.work_orders.delete(id);
                        } else {
                            // L'ora di avvio annotata sul device (solo locale) deve
                            // sopravvivere alla riga fresca del server: serve al consuntivo
                            const existing = await this.db.work_orders.get(id);
                            await this.db.work_orders.put({
                                ...change.row,
                                dirty: false,
                                ...(existing?.field_started_at ? { field_started_at: existing.field_started_at } : {}),
                            });
                        }
                        deferred.delete(id);
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
                            await this.db.asset_tags.where('asset_id').equals(id).delete();
                        } else {
                            await this.db.assets.put({ ...change.row, dirty: false });
                            if (change.row.tree) await this.db.trees.put(change.row.tree);
                            // I tag della riga server sostituiscono in blocco quelli locali
                            await this.db.asset_tags.where('asset_id').equals(id).delete();
                            if (change.row.tags?.length) await this.db.asset_tags.bulkPut(change.row.tags);
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
        // (consuntivi e segnalazioni scartati no: non sono replicati localmente)
        const deferredRow = await this.db.meta.get('deferred_ids');
        const deferred = new Set(deferredRow?.value ?? []);
        if (cmd.entity_id && ! ['work_log.add', 'issue.create', 'inspection.complete'].includes(cmd.type)) deferred.add(cmd.entity_id);
        await this.db.meta.put({ key: 'deferred_ids', value: [...deferred] });

        // Una creazione mai arrivata al server non deve restare come riga fantasma
        if (cmd.type === 'asset.create') {
            await this.db.assets.delete(cmd.entity_id);
            await this.db.trees.delete(cmd.entity_id);
            await this.db.asset_tags.where('asset_id').equals(cmd.entity_id).delete();
        }
        // Una transizione scartata lascia sul device uno stato ottimistico ormai
        // falso: si toglie il flag dirty così la verità del server (già richiesta
        // qui sopra per id) possa rimpiazzare la riga al prossimo pull
        if (cmd.type === 'work_order.transition') {
            const remaining = await this.db.sync_queue.where('entity_id').equals(cmd.entity_id).count();
            if (remaining === 0) {
                await this.db.work_orders.update(cmd.entity_id, { dirty: false });
            }
        }
        // Un'associazione tag scartata rimuove SOLO la riga ottimistica di questo
        // elemento: quella dell'eventuale elemento occupante è verità del server
        if (cmd.type === 'tag.associate' && cmd.payload?.uid) {
            await this.db.asset_tags
                .where('[tag_type+uid]').equals([cmd.payload.tag_type, cmd.payload.uid])
                .filter((t) => t.asset_id === cmd.entity_id)
                .delete();
        }

        await this.log('warn', `Operazione #${cmd.device_seq} (${cmd.type}) scartata dall'operatore.`);
        await this.notify();
    }

    /** Rimette in coda un comando rifiutato dopo la correzione.
     *  Con una chiave NUOVA: la vecchia è consumata sul server con l'esito
     *  "rifiutato" e un reinvio identico riceverebbe per sempre lo stesso no. */
    async retry(idempotencyKey) {
        await this.db.transaction('rw', this.db.sync_queue, async () => {
            const cmd = await this.db.sync_queue.get(idempotencyKey);
            if (! cmd) return;
            await this.db.sync_queue.delete(idempotencyKey);
            await this.db.sync_queue.add({
                ...cmd,
                idempotency_key: crypto.randomUUID(),
                status: 'PENDING',
                last_error: null,
            });
        });
        await this.log('info', 'Operazione rimessa in coda per un nuovo invio.');
        await this.notify();
    }
}
