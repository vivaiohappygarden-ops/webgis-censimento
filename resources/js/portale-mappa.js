// Mappa del portale pubblico: pacchetto a sé, senza Vue, senza Inertia e
// senza service worker. Sul dispositivo del cittadino non deve finire
// l'applicazione gestionale né la cache dell'app di campo.

import 'maplibre-gl/dist/maplibre-gl.css';
import * as maplibregl from 'maplibre-gl';
import { setWorkerUrl } from 'maplibre-gl';
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

setWorkerUrl(maplibreWorkerUrl);

// Valore che nessun codice può assumere: serve a spegnere l'evidenziazione
const NESSUNO = '__nessuno__';

const config = window.__portale;
if (config) avvia(config);

/**
 * Raggio della chioma in pixel, alla misura vera.
 *
 * Un metro vale 2^zoom / (78271.517 * cos(latitudine)) pixel: con
 * "interpolate exponential base 2" fra due soli estremi la formula resta
 * esatta a ogni ingrandimento intermedio, non approssimata. La latitudine è
 * quella del centro del territorio: dentro un Comune il coseno cambia meno
 * di un millesimo, e così il raggio non ha bisogno di essere ricalcolato a
 * ogni spostamento della carta.
 *
 * Il diametro arriva dal riquadro vettoriale (proprietà chioma_m). Dove non
 * è stato rilevato il raggio vale zero e non si disegna niente: la legenda
 * dice infatti "sugli alberi di cui è stato rilevato il diametro".
 */
function raggioChioma(latitudine) {
    const cosLat = Math.cos(((latitudine ?? 0) * Math.PI) / 180) || 1;
    const pixelPerMetro = (zoom) => (2 ** zoom) / (78271.517 * cosLat);
    const raggio = (zoom) => [
        '*', ['/', ['coalesce', ['to-number', ['get', 'chioma_m']], 0], 2], pixelPerMetro(zoom),
    ];

    return ['interpolate', ['exponential', 2], ['zoom'], 15, raggio(15), 22, raggio(22)];
}

function stileCon(sfondo, urlTile) {
    // I quattro stati, scritti una volta sola: li portano il punto e la chioma
    const coloreStato = [
        'match',
        ['get', 'stato'],
        'sano', config.colori.sano,
        'cura', config.colori.cura,
        'potare', config.colori.potare,
        'verifica', config.colori.verifica,
        '#6b7280',
    ];

    return {
        version: 8,
        sources: {
            sfondo: {
                type: 'raster',
                tiles: [sfondo.url],
                tileSize: 256,
                // Oltre questo livello il fornitore non ha immagini. Senza
                // dichiararlo la mappa chiede riquadri inesistenti e si
                // svuota ingrandendo; dichiarandolo, ingrandisce l'ultima
                // immagine disponibile: sgranata, ma c'è
                maxzoom: sfondo.zoom_massimo ?? 19,
                attribution: sfondo.attribuzione,
            },
            elementi: { type: 'vector', tiles: [urlTile], minzoom: 5, maxzoom: 22 },
        },
        layers: [
            { id: 'sfondo', type: 'raster', source: 'sfondo' },
            {
                id: 'aree',
                type: 'fill',
                source: 'elementi',
                'source-layer': 'elementi',
                filter: ['==', ['geometry-type'], 'Polygon'],
                paint: {
                    // Il verde delle aree e il bosco dei contorni escono dalla
                    // tavolozza del Comune: la carta è dello stesso colore del
                    // portale, non di un verde qualsiasi
                    'fill-color': config.verde ?? '#4d7c0f',
                    'fill-opacity': 0.35,
                    'fill-outline-color': config.bosco ?? '#3f6212',
                },
            },
            {
                id: 'linee',
                type: 'line',
                source: 'elementi',
                'source-layer': 'elementi',
                filter: ['==', ['geometry-type'], 'LineString'],
                paint: { 'line-color': config.bosco ?? '#4d7c0f', 'line-width': 2 },
            },
            // La chioma alla misura vera, sotto il punto: da vicino l'albero
            // occupa sulla carta lo spazio che occupa in strada. Il velo
            // chiaro la tiene leggibile anche sull'ortofoto e sulla carta scura
            {
                id: 'chiome',
                type: 'circle',
                source: 'elementi',
                'source-layer': 'elementi',
                minzoom: 15.5,
                filter: ['all',
                    ['==', ['geometry-type'], 'Point'],
                    ['>', ['coalesce', ['to-number', ['get', 'chioma_m']], 0], 0]],
                paint: {
                    'circle-radius': raggioChioma(config.centro?.[1]),
                    'circle-color': 'rgba(255, 255, 255, 0.16)',
                    'circle-stroke-width': 1.2,
                    'circle-stroke-color': coloreStato,
                    'circle-stroke-opacity': 0.75,
                },
            },
            {
                id: 'punti',
                type: 'circle',
                source: 'elementi',
                'source-layer': 'elementi',
                filter: ['==', ['geometry-type'], 'Point'],
                paint: {
                    'circle-radius': ['interpolate', ['linear'], ['zoom'], 12, 3.5, 16, 6, 19, 9],
                    'circle-color': coloreStato,
                    'circle-stroke-width': 1.5,
                    'circle-stroke-color': sfondo.scuro ? 'rgba(255,255,255,0.85)' : 'rgba(255,255,255,0.9)',
                },
            },
            // Evidenziazione dell'elemento aperto: un cerchio sui punti, un
            // contorno su aiuole e siepi. Un livello a cerchi da solo
            // marcherebbe ogni vertice del poligono
            {
                id: 'selezionato',
                type: 'circle',
                source: 'elementi',
                'source-layer': 'elementi',
                filter: ['all', ['==', ['geometry-type'], 'Point'], ['==', ['get', 'codice'], NESSUNO]],
                paint: {
                    'circle-radius': ['interpolate', ['linear'], ['zoom'], 12, 7, 16, 11, 19, 15],
                    'circle-color': 'rgba(0,0,0,0)',
                    'circle-stroke-width': 3,
                    // Oro, l'accento del portale: il rosso di prima era il
                    // colore dello stato "in verifica" e un albero scelto
                    // sembrava un albero da controllare
                    'circle-stroke-color': config.accento ?? '#dc2626',
                },
            },
            {
                id: 'selezionato-contorno',
                type: 'line',
                source: 'elementi',
                'source-layer': 'elementi',
                filter: ['all', ['!=', ['geometry-type'], 'Point'], ['==', ['get', 'codice'], NESSUNO]],
                paint: { 'line-color': config.accento ?? '#dc2626', 'line-width': 3 },
            },
        ],
    };
}

function avvia(config) {
    let sfondo = config.sfondi[0];

    // Nella home la mappa è un'anteprima: si guarda, non si manovra, e un
    // clic qualsiasi porta alla mappa vera. Senza questo, due mappe piene
    // sullo stesso sito confonderebbero e mangerebbero dati al telefono
    const anteprima = !! config.anteprima;

    const map = new maplibregl.Map({
        container: anteprima ? 'anteprima-mappa' : 'mappa',
        style: stileCon(sfondo, config.urlTile),
        center: config.centro,
        zoom: config.zoom,
        interactive: ! anteprima,
        attributionControl: { compact: true },
    });

    // Riferimento per il collaudo automatico nel browser
    window.__mappa = map;

    if (anteprima) {
        if (config.estensione) {
            map.fitBounds([config.estensione.sw, config.estensione.ne],
                { padding: 36, animate: false, maxZoom: 17 });
        }

        return;
    }

    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.addControl(new maplibregl.GeolocateControl({ trackUserLocation: false }), 'top-right');
    map.addControl(new maplibregl.ScaleControl({ maxWidth: 120, unit: 'metric' }), 'bottom-left');

    if (config.estensione) {
        map.fitBounds([config.estensione.sw, config.estensione.ne], { padding: 60, animate: false, maxZoom: 18 });
    }

    // Selettore degli sfondi
    const selettore = document.getElementById('sfondi');
    if (selettore) {
        selettore.addEventListener('click', (evento) => {
            const scelto = evento.target.closest('button[data-sfondo]');
            if (! scelto) return;
            sfondo = config.sfondi.find((s) => s.id === scelto.dataset.sfondo) ?? sfondo;
            selettore.querySelectorAll('button[data-sfondo]').forEach((b) => {
                b.classList.toggle('attivo', b.dataset.sfondo === sfondo.id);
            });
            map.setStyle(stileCon(sfondo, config.urlTile));
        });
    }

    const pannello = document.getElementById('pannello');
    const contenuto = document.getElementById('pannello-contenuto');
    const chiudi = document.getElementById('pannello-chiudi');

    function evidenzia(codice) {
        const scelto = codice ?? NESSUNO;
        if (map.getLayer('selezionato')) {
            map.setFilter('selezionato',
                ['all', ['==', ['geometry-type'], 'Point'], ['==', ['get', 'codice'], scelto]]);
        }
        if (map.getLayer('selezionato-contorno')) {
            map.setFilter('selezionato-contorno',
                ['all', ['!=', ['geometry-type'], 'Point'], ['==', ['get', 'codice'], scelto]]);
        }
    }

    async function apri(codice) {
        pannello.hidden = false;
        contenuto.innerHTML = '<p class="attesa">Caricamento…</p>';
        evidenzia(codice);
        try {
            const risposta = await fetch(`${config.urlElemento}/${encodeURIComponent(codice)}?riquadro=1`, {
                headers: { 'X-Requested-With': 'fetch' },
            });
            contenuto.innerHTML = risposta.ok
                ? await risposta.text()
                : '<p class="attesa">Scheda non disponibile.</p>';
        } catch (errore) {
            contenuto.innerHTML = '<p class="attesa">Scheda non raggiungibile: controlla la connessione.</p>';
        }
    }

    chiudi?.addEventListener('click', () => {
        pannello.hidden = true;
        evidenzia(null);
    });

    ['punti', 'aree', 'linee'].forEach((strato) => {
        map.on('click', strato, (evento) => {
            const codice = evento.features?.[0]?.properties?.codice;
            if (codice) apri(codice);
        });
        map.on('mouseenter', strato, () => { map.getCanvas().style.cursor = 'pointer'; });
        map.on('mouseleave', strato, () => { map.getCanvas().style.cursor = ''; });
    });

    // Suggerimento al passaggio del mouse, come sui portali di riferimento
    const suggerimento = new maplibregl.Popup({ closeButton: false, closeOnClick: false, offset: 10 });
    map.on('mousemove', 'punti', (evento) => {
        const p = evento.features?.[0]?.properties;
        if (! p) return;
        suggerimento
            .setLngLat(evento.lngLat)
            .setText(`${p.tipo ?? ''} ${p.tipo_nome ?? ''} ${p.codice ?? ''}`.trim())
            .addTo(map);
    });
    map.on('mouseleave', 'punti', () => suggerimento.remove());

    if (config.apri) apri(config.apri);
}
