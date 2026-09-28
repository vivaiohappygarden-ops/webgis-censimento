<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marche temporali (RFC 3161)
    |--------------------------------------------------------------------------
    |
    | La marca temporale certifica che un documento esisteva, cosi' com'e', a
    | un istante certo: la rilascia una Time Stamping Authority (TSA) accreditata
    | e vale come prova opponibile. Il programma parla il protocollo standard
    | RFC 3161 sopra HTTPS con nome utente e password dell'account: e' quello
    | usato da Aruba (Marca Temporale), InfoCert, Namirial e dagli altri
    | fornitori italiani. Le marche si comprano a lotti dal fornitore.
    |
    | Senza credenziali le marche restano spente e la pagina Documenti lo
    | dice. Queste sono le credenziali della piattaforma, valide per tutte le
    | organizzazioni; ogni organizzazione puo' inserire le proprie da
    | Documenti (chi gestisce gli utenti), e quelle vincono su queste.
    |
    */

    // Indirizzo del servizio: quello di Aruba di serie
    'url' => env('MARCHE_URL', 'https://servizi.arubapec.it/tsa/ngrequest.php'),

    'utente' => env('MARCHE_UTENTE'),
    'password' => env('MARCHE_PASSWORD'),

    // Identificativo (OID) della politica di marcatura, se il fornitore lo chiede
    'policy' => env('MARCHE_POLICY'),

    // Marche al giorno per account, contate su tutte le organizzazioni che lo
    // usano: 0 = senza tetto. Serve a non svuotare un lotto per errore.
    'quota_giorno' => (int) env('MARCHE_QUOTA_GIORNO', 10),

    'timeout' => (int) env('MARCHE_TIMEOUT', 25),

    // File PEM con i certificati della TSA (la catena fino alla radice): con
    // questo il programma verifica anche la firma della marca, con openssl.
    // Senza, verifica che il file conservato sia quello marcato e dichiara
    // che la firma va controllata fuori dal programma.
    'catena' => env('MARCHE_CATENA'),

    'openssl' => env('MARCHE_OPENSSL', 'openssl'),

];
