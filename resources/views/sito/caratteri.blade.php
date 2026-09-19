{{-- Carattere del sito aziendale: Inter, ospitato sul nostro server (stessi
     file dei portali civici, public/portale/font). Nessuna chiamata a Google
     o ad altri domini: e' quello che permette di scrivere in pie' di pagina
     che il sito non contatta nessuno. Licenza SIL Open Font
     (public/portale/font/OFL-Inter.txt).
     I quattro file sono carattere variabile con asse di peso 100-900
     (verificato sull'asse fvar il 19/09/2026): la dichiarazione dice i pesi
     che ci sono davvero. Il sito usa 400, 500, 600 e 700; il corsivo serve
     ai nomi botanici. Sottoinsieme latino separato da quello esteso, cosi'
     una pagina in italiano scarica solo il primo. --}}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 100 900;
    font-display: swap;
    src: url('/portale/font/inter-tondo-latin.woff2') format('woff2');
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 100 900;
    font-display: swap;
    src: url('/portale/font/inter-tondo-latin-ext.woff2') format('woff2');
    unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
}
@font-face {
    font-family: 'Inter';
    font-style: italic;
    font-weight: 100 900;
    font-display: swap;
    src: url('/portale/font/inter-corsivo-latin.woff2') format('woff2');
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
@font-face {
    font-family: 'Inter';
    font-style: italic;
    font-weight: 100 900;
    font-display: swap;
    src: url('/portale/font/inter-corsivo-latin-ext.woff2') format('woff2');
    unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
}
