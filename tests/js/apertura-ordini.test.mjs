import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/**
 * Le viste dei lavori (agenda, rendiconto, Gantt) aprono la scheda di un
 * ordine dicendo alla pagina QUALE ordine: passano l'id, non l'ordine intero.
 *
 * Il Gantt passava l'oggetto e la pagina componeva l'indirizzo
 * ".../work-orders/[object Object]": un 404 che non spiegava niente
 * (segnalato dal committente il 17/09/2026). Non c'e' un banco di prova per
 * i componenti Vue: qui si legge il sorgente e si controlla il contratto.
 */
const componenti = [
    'resources/js/Components/WorkAgenda.vue',
    'resources/js/Components/WorkReport.vue',
    'resources/js/Components/GanttLavori.vue',
];

for (const percorso of componenti) {
    test(`${percorso}: ogni apertura passa l'id dell'ordine`, () => {
        const sorgente = readFileSync(new URL('../../' + percorso, import.meta.url), 'utf8');
        const aperture = [...sorgente.matchAll(/emit\('open',\s*([^)]+)\)/g)].map((m) => m[1].trim());

        assert.ok(aperture.length > 0, 'il componente deve avere almeno un punto in cui apre un ordine');
        for (const argomento of aperture) {
            assert.match(argomento, /\.id$/, `emit('open', ${argomento}) deve passare l'id, non l'oggetto`);
        }
    });
}
