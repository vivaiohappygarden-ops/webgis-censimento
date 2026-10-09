/*
 * I disegni delle icone della veste "Lo strumento" (bozza B, 09/10/2026):
 * ventiquattro simboli piu' qualche aggiunta, tratto di 1,5 px e spigoli vivi
 * su una griglia di 20 unita'. Li monta Components/Nuovo/Icona.vue; sono
 * disegni nostri, non una libreria, e stanno qui in un posto solo.
 */
const PUNTI = 'fill="currentColor" stroke="none"';

export const ICONE = {
    oggi: `<rect x="3" y="4" width="14" height="13"/><line x1="3" y1="8" x2="17" y2="8"/><line x1="6.5" y1="2" x2="6.5" y2="5"/><line x1="13.5" y1="2" x2="13.5" y2="5"/><rect x="9" y="11" width="2.5" height="2.5" ${PUNTI}/>`,
    patrimonio: '<circle cx="10" cy="7.5" r="4.5"/><line x1="10" y1="12" x2="10" y2="18"/><line x1="7" y1="18" x2="13" y2="18"/>',
    lavori: '<line x1="10" y1="2" x2="10" y2="10"/><line x1="8" y1="2" x2="12" y2="2"/><path d="M7 10h6v4a3 3 0 0 1-6 0z"/>',
    documenti: '<path d="M5 2h7l4 4v12H5z"/><path d="M12 2v4h4"/><line x1="7.5" y1="10" x2="13.5" y2="10"/><line x1="7.5" y1="13.5" x2="13.5" y2="13.5"/>',
    committenti: '<path d="M3 8l7-5 7 5z"/><line x1="5" y1="8" x2="5" y2="15"/><line x1="8.3" y1="8" x2="8.3" y2="15"/><line x1="11.7" y1="8" x2="11.7" y2="15"/><line x1="15" y1="8" x2="15" y2="15"/><line x1="3" y1="17" x2="17" y2="17"/>',
    impostazioni: `<line x1="3" y1="5" x2="17" y2="5"/><line x1="3" y1="10" x2="17" y2="10"/><line x1="3" y1="15" x2="17" y2="15"/><rect x="10.5" y="3.5" width="3" height="3" ${PUNTI}/><rect x="5.5" y="8.5" width="3" height="3" ${PUNTI}/><rect x="12" y="13.5" width="3" height="3" ${PUNTI}/>`,
    guida: '<path d="M3 4h6a2 2 0 0 1 2 2v11a2 2 0 0 0-2-2H3z"/><path d="M17 4h-6a2 2 0 0 0-2 2v11a2 2 0 0 1 2-2h6z"/>',
    cerca: '<circle cx="8.5" cy="8.5" r="5"/><line x1="12.5" y1="12.5" x2="17" y2="17"/>',
    stampa: '<path d="M6 7V3h8v4"/><rect x="3" y="7" width="14" height="7"/><rect x="6" y="11" width="8" height="6"/>',
    mappa: '<path d="M3 5l5-2 4 2 5-2v12l-5 2-4-2-5 2z"/><line x1="8" y1="3" x2="8" y2="15"/><line x1="12" y1="5" x2="12" y2="17"/>',
    foto: '<rect x="2" y="6" width="16" height="11"/><circle cx="10" cy="11.5" r="3.2"/><path d="M7 6l1.5-2.5h3L13 6"/>',
    modifica: '<path d="M13 3l4 4L7 17H3v-4z"/><line x1="11" y1="5" x2="15" y2="9"/>',
    nuovo: '<rect x="3" y="3" width="14" height="14"/><line x1="10" y1="6.5" x2="10" y2="13.5"/><line x1="6.5" y1="10" x2="13.5" y2="10"/>',
    filtri: '<line x1="3" y1="5" x2="17" y2="5"/><line x1="5.5" y1="10" x2="14.5" y2="10"/><line x1="8" y1="15" x2="12" y2="15"/>',
    esporta: '<path d="M4 13v4h12v-4"/><line x1="10" y1="3" x2="10" y2="12"/><path d="M6.5 6.5L10 3l3.5 3.5"/>',
    altro: `<rect x="2.5" y="8.5" width="3" height="3" ${PUNTI}/><rect x="8.5" y="8.5" width="3" height="3" ${PUNTI}/><rect x="14.5" y="8.5" width="3" height="3" ${PUNTI}/>`,
    cartellino: '<rect x="6" y="2" width="8" height="16"/><circle cx="10" cy="4.5" r="0.9"/><rect x="7.8" y="7.5" width="1.8" height="1.8"/><rect x="10.4" y="7.5" width="1.8" height="1.8"/><rect x="7.8" y="10.1" width="1.8" height="1.8"/><rect x="10.4" y="12.7" width="1.8" height="1.8"/>',
    vta: '<line x1="5" y1="4" x2="15" y2="4"/><line x1="10" y1="4" x2="10" y2="13"/><path d="M7 13l3 5 3-5z"/>',
    area: '<path d="M4 6l12-2v10L4 16z"/><path d="M6 14l7-7M6 10.5l3.5-3.5M10 14l4-4"/>',
    segnalazione: '<line x1="5" y1="3" x2="5" y2="18"/><path d="M5 3h10l-2.5 3.5L15 10H5"/>',
    ispezione: '<rect x="4" y="3" width="12" height="14"/><path d="M7 10l2.5 2.5L13 7.5"/>',
    utente: '<circle cx="10" cy="6.5" r="3.2"/><path d="M4 17a6 6 0 0 1 12 0"/>',
    esci: '<path d="M12 3H4v14h8"/><line x1="8" y1="10" x2="17" y2="10"/><path d="M14 7l3 3-3 3"/>',
    campo: '<rect x="6" y="2" width="8" height="16"/><line x1="8.5" y1="15.5" x2="11.5" y2="15.5"/>',
    chiudi: '<line x1="5" y1="5" x2="15" y2="15"/><line x1="15" y1="5" x2="5" y2="15"/>',
    menu: '<line x1="3" y1="5" x2="17" y2="5"/><line x1="3" y1="10" x2="17" y2="10"/><line x1="3" y1="15" x2="17" y2="15"/>',
    piattaforma: '<rect x="3" y="3" width="6" height="6"/><rect x="11" y="3" width="6" height="6"/><rect x="3" y="11" width="6" height="6"/><rect x="11" y="11" width="6" height="6"/>',
    portale: '<circle cx="10" cy="10" r="7"/><path d="M3 10h14M10 3c2.5 2.5 2.5 11.5 0 14M10 3c-2.5 2.5-2.5 11.5 0 14"/>',
    impresa: '<path d="M3 17V9l7-5 7 5v8z"/><rect x="8" y="12" width="4" height="5"/>',
    accesso: '<rect x="4" y="9" width="12" height="9"/><path d="M7 9V6a3 3 0 0 1 6 0v3"/><rect x="9" y="12.5" width="2" height="2" fill="currentColor" stroke="none"/>',
};
