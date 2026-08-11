import Dexie from 'dexie';

/**
 * Database locale della PWA operatore (OFFLINE-SYNC §3.1, sottoinsieme v1).
 * Un database per tenant+utente: evita mescolanze su device condiviso.
 */
export function openFieldDb(tenantId, userId) {
    const name = `wg_${String(tenantId).slice(0, 8)}_${String(userId).slice(0, 8)}`;
    const db = new Dexie(name);

    db.version(1).stores({
        meta: '&key',
        areas: '&id, name',
        assets: '&id, area_id, object_type_id, census_code, status, updated_at',
        trees: '&asset_id, species',
        catalog_types: '&id, code',
        custom_fields: '&id, object_type_id',
        sync_queue: '&idempotency_key, device_seq, status, entity_id, [status+device_seq]',
        conflicts: '&idempotency_key, entity_id, resolved',
        sync_log: '++seq, ts, level',
    });

    return db;
}
