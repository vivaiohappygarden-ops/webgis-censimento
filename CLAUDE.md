# Convenzioni di progetto

WebGIS multi-tenant per la gestione del verde (censimento, catasto alberi/VTA, lavori, CAM).
Riferimenti: `PROPOSTA-ARCHITETTURA.md` (approvata 10/08/2026), `docs/GIS-DATA-MODEL.md`,
`docs/OFFLINE-SYNC.md`, `docs/ZEBRA-INTEGRATION.md`.

## Stile interfaccia (decisione committente 10/08/2026, agg. 11/08/2026)

- Stile **molto analitico, senza emoji**: nessuna emoji o icona pittografica in etichette,
  pulsanti, titoli, messaggi o placeholder dell'interfaccia.
- Stile **pulito e minimale**; carattere dell'interfaccia: **caratteri di sistema**
  (`system-ui, -apple-system, 'Segoe UI', Roboto, …`, decisione committente 15/08/2026
  che sostituisce Courier New), definiti in `resources/css/app.css`
  (`--font-sans`/`--font-mono` + `.maplibregl-map`): niente webfont esterni.
  Cifre tabellari (`font-variant-numeric: tabular-nums`) per tenere incolonnati i numeri.
- Le **stampe PDF** restano su **DejaVu Sans Mono** (font incorporato in dompdf): non
  seguono il foglio di stile dell'interfaccia.
- Ammessi solo simboli tipografici funzionali: frecce di navigazione (← →), "✕" per chiudere.
- Preferire testo sobrio, dati in evidenza, tabelle dense; l'informazione prevale sulla decorazione.
- Lingua dell'interfaccia e dei messaggi: italiano.

## Stack e vincoli

- Laravel 13 (PHP 8.4), Inertia + Vue 3, Tailwind v4, MapLibre GL v6
  (`import * as maplibregl`, worker registrato via `?worker&url` in `resources/js/app.js`).
- PostgreSQL 16 + PostGIS: geometrie SRID 4326, misure calcolate in EPSG 7791 da colonne
  GENERATED; logica critica (versioning, audit, coerenza geometria/tipo) nei trigger DB.
- Multi-tenant per riga: `tenant_id` ovunque, `TenantScope` (con guardia `Auth::hasUser()`,
  non rimuoverla: evita la ricorsione con SessionGuard) + trait `BelongsToTenant`.
- RBAC spatie/laravel-permission v8 con teams (`tenant_id`); ruoli: amministratore,
  tecnico, operatore, cliente.
- Aggiornamenti asset con optimistic locking (campo `version`, 409 in conflitto) dentro
  transazione con `lockForUpdate`.
- Catalogo Modello Dati v2.1: 387 codici in `database/seeders/data/catalogo_md_v21.csv`,
  installati da `CatalogInstaller`; i tipi CAM sono immutabili.
- Export CAM: `CamExporter` (GeoJSON + shapefile via ogr2ogr, riproiezione RDN2008
  EPSG 7791-7794, date GGMMAAAA).

## Flusso di lavoro

- Direttiva committente 11/08/2026: **proseguire sempre** con il blocco successivo della roadmap
  senza chiedere conferma a ogni passaggio; chiedere solo per decisioni irreversibili o acquisti.
- Branch di sviluppo: `claude/aruba-hosting-specifics-atsiy4`; mai push altrove.
- Test: `php artisan test` (DB `webgis_test`); la suite deve restare verde prima del push.
- Verifica ogni blocco anche nel browser reale (Playwright/Chromium) oltre che con i test.
- Il committente non è tecnico: i resoconti si scrivono in italiano semplice, senza tecnicismi
  non spiegati e senza emoji.
