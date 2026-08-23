<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tipologie di verde urbano (rilevazione ISTAT)
    |--------------------------------------------------------------------------
    |
    | Classificazione della località, usata nella scheda e nelle consegne agli
    | enti. Sta qui e non nel programma perché è una tassonomia esterna che
    | cambia con le rilevazioni: quando cambia si aggiorna questo elenco senza
    | toccare il codice.
    |
    | ATTENZIONE: queste voci vanno confrontate con la rilevazione ISTAT
    | vigente prima di usarle in una consegna ufficiale. Il programma non
    | pretende di essere la fonte: si limita a registrare la voce scelta dal
    | tecnico, che resta responsabile della classificazione.
    |
    | Le chiavi restano stabili anche se cambia l'etichetta: sono quelle
    | salvate nell'archivio.
    |
    */

    'verde_urbano' => [
        'verde_storico' => 'Verde storico',
        'grandi_parchi' => 'Grandi parchi urbani',
        'verde_attrezzato' => 'Verde attrezzato',
        'arredo_urbano' => 'Aree di arredo urbano',
        'forestazione_urbana' => 'Forestazione urbana',
        'giardini_scolastici' => 'Giardini scolastici',
        'orti_urbani' => 'Orti urbani',
        'orti_botanici' => 'Orti botanici e giardini zoologici',
        'aree_sportive' => 'Aree sportive all\'aperto',
        'cimiteri' => 'Cimiteri',
        'aree_boschive' => 'Aree boschive',
        'altro' => 'Altra tipologia',
    ],

];
