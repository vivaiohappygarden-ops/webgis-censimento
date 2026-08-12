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

    // v2: tag fisici per la scansione barcode/QR/NFC in campo
    db.version(2).stores({
        asset_tags: '&id, asset_id, uid, [tag_type+uid]',
    });

    // v3: foto scattate offline — i Blob pesanti stanno in una tabella separata
    // così le scansioni della coda non caricano i binari (OFFLINE-SYNC §3.1)
    db.version(3).stores({
        photo_blobs: '&photo_id',
        photo_uploads: '&photo_id, asset_id, status, [status+created_at]',
    });

    // v4: ordini di lavoro assegnati all'operatore ("I miei lavori")
    db.version(4).stores({
        work_orders: '&id, status, code',
    });

    // v5: modelli di ispezione per le checklist compilate in campo
    db.version(5).stores({
        inspection_templates: '&id, target, name',
    });

    return db;
}
