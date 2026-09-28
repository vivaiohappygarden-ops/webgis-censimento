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
    | fornitori italiani. Ai clienti le marche le vende DAMA S.R.L. insieme al
    | programma, a pacchetti: l'account del servizio e' quello di DAMA e le sue
    | credenziali stanno qui (decisione committente 28/09/2026). Un cliente con
    | un proprio account puo' comunque inserirlo da Documenti.
    |
    | Ai clienti le marche le vende DAMA S.R.L. a pacchetti, insieme al
    | programma (decisione committente 28/09/2026: "da noi si comprano"). Le
    | credenziali stanno SOLO nell'organizzazione (cifrate nelle sue
    | impostazioni, inserite da Documenti o dalla console della piattaforma):
    | chi affitta la piattaforma a un'altra azienda non deve vedersi consumare
    | il proprio lotto, quindi non c'e' un account comune in questo file. Qui
    | restano solo i valori di serie e le regolazioni del server.
    |
    */

    // Indirizzo del servizio proposto di serie nel modulo: quello di Aruba
    'url' => env('MARCHE_URL', 'https://servizi.arubapec.it/tsa/ngrequest.php'),

    // Marche al giorno per account, se l'organizzazione non indica un proprio
    // tetto: 0 = senza tetto. Serve a non svuotare un lotto per errore.
    'quota_giorno' => (int) env('MARCHE_QUOTA_GIORNO', 10),

    'timeout' => (int) env('MARCHE_TIMEOUT', 25),

    // File PEM con i certificati della TSA (la catena fino alla radice): con
    // questo il programma verifica anche la firma della marca, con openssl.
    // Senza, verifica che il file conservato sia quello marcato e dichiara
    // che la firma va controllata fuori dal programma.
    'catena' => env('MARCHE_CATENA'),

    'openssl' => env('MARCHE_OPENSSL', 'openssl'),

];
