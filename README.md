# WebGIS Censimento — Piattaforma di gestione del verde

Piattaforma WebGIS professionale per la gestione del patrimonio verde: censimento georeferenziato,
catasto alberi e VTA, ispezioni aree gioco, ordini di lavoro e rendicontazione, app di campo
offline con barcode/QR/NFC e integrazione terminali Zebra.

> Il nome definitivo del prodotto è in via di definizione (vedi domanda Q8 della proposta).

## Stato del progetto

**Fase 0 — Fondazioni** (approvata il 10/08/2026).
Il documento guida del progetto è [`PROPOSTA-ARCHITETTURA.md`](PROPOSTA-ARCHITETTURA.md):
requisiti, architettura approvata, roadmap e decisioni.

## Architettura in sintesi

| Componente | Tecnologia |
|---|---|
| Backend + backoffice | Laravel 13 (PHP 8.4), Inertia.js |
| Database | PostgreSQL 16 + PostGIS 3.4 |
| Cache e code | Redis 7 |
| App di campo | PWA Vue 3 offline-first (IndexedDB/Dexie + service worker) |
| Mappa | MapLibre GL JS + vector tiles (ST_AsMVT) |
| File (foto/documenti) | Object storage S3-compatible (dev: MinIO; prod: Aruba Cloud Object Storage) |
| Deploy | Docker Compose su Aruba Cloud (piano economico, v. proposta §7) |

## Documentazione tecnica

| Documento | Contenuto |
|---|---|
| [`PROPOSTA-ARCHITETTURA.md`](PROPOSTA-ARCHITETTURA.md) | Analisi requisiti, architettura, roadmap, decisioni |
| [`docs/GIS-DATA-MODEL.md`](docs/GIS-DATA-MODEL.md) | Schema dati, PostGIS, conformità CAM/Modello Dati v2.1 |
| [`docs/OFFLINE-SYNC.md`](docs/OFFLINE-SYNC.md) | Design offline-first e sincronizzazione |
| [`docs/ZEBRA-INTEGRATION.md`](docs/ZEBRA-INTEGRATION.md) | Integrazione Zebra, barcode/QR/NFC, stampa ZPL, acquisti consigliati |

## Sviluppo locale

Prerequisiti: PHP 8.4 (con `pdo_pgsql`, `gd`, `exif`, `intl`), Composer, Node 22+, Docker.

```bash
# 1. Servizi di infrastruttura (PostgreSQL+PostGIS, Redis, MinIO, Mailpit)
docker compose up -d

# 2. Dipendenze e configurazione
composer install
cp .env.example .env
php artisan key:generate

# 3. Database
php artisan migrate --seed

# 4. Avvio
php artisan serve          # backend su http://localhost:8000
npm install && npm run dev # asset frontend
```

Servizi di sviluppo: MinIO console `http://localhost:9001` (webgis / webgis-dev-secret),
Mailpit (email intercettate) `http://localhost:8025`.

## Struttura del repository

```
app/            codice applicativo Laravel (moduli in app/Modules/* dalla Fase 1)
database/       migrazioni e seeder (incl. seed catalogo Modello Dati v2.1)
docs/           documentazione tecnica
resources/      viste, asset, PWA operatore (dalla Fase 3)
compose.yaml    servizi di sviluppo
.github/        CI (test su PostGIS, security scan)
```

## Licenza e proprietà

Codice proprietario. I documenti GreenSpaces/R3GIS citati nella proposta sono stati usati
esclusivamente come riferimento funzionale (nessun codice, grafica o dato proprietario copiato).
