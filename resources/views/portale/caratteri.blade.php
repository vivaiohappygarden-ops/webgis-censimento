{{-- Caratteri del portale pubblico, ospitati sul nostro server.
     Un sito civico non deve far partire richieste verso Google: il browser
     del cittadino chiederebbe i caratteri a fonts.gstatic.com e quel
     passaggio, per il GDPR, e' un trasferimento di dati a un terzo.
     I file stanno in public/portale/font, licenza SIL Open Font License
     (OFL-Fraunces.txt, OFL-Inter.txt nella stessa cartella).
     Blocchi generati dal foglio ufficiale di Google Fonts: gli intervalli
     unicode sono quelli originali, cosi' il browser scarica solo il taglio
     che gli serve (latino, oppure latino esteso). --}}
@font-face {
    font-family: 'Fraunces';
    font-style: italic;
    font-weight: 300 600;
    font-display: swap;
    src: url('/portale/font/fraunces-corsivo-latin-ext.woff2') format('woff2');
    unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
}
@font-face {
    font-family: 'Fraunces';
    font-style: italic;
    font-weight: 300 600;
    font-display: swap;
    src: url('/portale/font/fraunces-corsivo-latin.woff2') format('woff2');
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
@font-face {
    font-family: 'Fraunces';
    font-style: normal;
    font-weight: 300 600;
    font-display: swap;
    src: url('/portale/font/fraunces-tondo-latin-ext.woff2') format('woff2');
    unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
}
@font-face {
    font-family: 'Fraunces';
    font-style: normal;
    font-weight: 300 600;
    font-display: swap;
    src: url('/portale/font/fraunces-tondo-latin.woff2') format('woff2');
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 300 600;
    font-display: swap;
    src: url('/portale/font/inter-tondo-latin-ext.woff2') format('woff2');
    unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 300 600;
    font-display: swap;
    src: url('/portale/font/inter-tondo-latin.woff2') format('woff2');
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
