<?php

namespace App\Support;

use App\Services\Tenancy\TenantProvisioner;

/**
 * I permessi del programma spiegati in italiano, raggruppati come li
 * ragiona chi assegna un ruolo.
 *
 * Un elenco di sigle ("assets.update") non si puo' dare in mano a un
 * amministratore che deve decidere chi puo' fare cosa: qui ogni permesso ha
 * il suo nome e la sua riga di spiegazione, e stanno in un posto solo perche'
 * li usano la pagina dei ruoli, l'API e le prove.
 */
final class Permessi
{
    /** Gruppi nell'ordine in cui si mostrano, con i permessi che contengono. */
    public const GRUPPI = [
        'Censimento' => ['assets.view', 'assets.create', 'assets.update', 'assets.delete'],
        'Territorio' => ['areas.view', 'areas.create', 'areas.update', 'areas.delete'],
        'Catalogo' => ['catalog.view', 'catalog.manage'],
        'Committenti' => ['clients.view', 'clients.manage'],
        'Lavori' => ['works.view', 'works.manage'],
        'Amministrazione' => ['users.manage'],
        'Portali esterni' => ['portal.view', 'impresa.view'],
    ];

    /** Nome e spiegazione di ogni permesso. */
    public const ETICHETTE = [
        'assets.view' => ['Vedere il censimento', 'Elenco, mappa e schede degli elementi censiti'],
        'assets.create' => ['Censire nuovi elementi', 'Aggiungere alberi, aree verdi, arredo'],
        'assets.update' => ['Modificare le schede', 'Misure, specie, foto, valutazioni di stabilita'],
        'assets.delete' => ['Eliminare le schede', "Cancellare un elemento censito: si usa di rado, l'abbattimento e' un'altra cosa"],
        'areas.view' => ['Vedere il territorio', 'Committenti, sedi, localita e aree'],
        'areas.create' => ['Creare aree', 'Nuove localita e nuove aree di gestione'],
        'areas.update' => ['Modificare le aree', 'Nome, confini, stato e dati di un area'],
        'areas.delete' => ['Eliminare le aree', 'Solo aree vuote: quelle con elementi non si cancellano'],
        'catalog.view' => ['Vedere il catalogo', 'I tipi di oggetto del Modello Dati'],
        'catalog.manage' => ['Gestire il catalogo', 'Tipi personalizzati e campi aggiuntivi delle schede'],
        'clients.view' => ['Vedere i committenti', 'Anagrafica di Comuni e clienti privati'],
        'clients.manage' => ['Gestire i committenti', 'Creare e modificare anagrafiche, contratti e portali'],
        'works.view' => ['Vedere i lavori', 'Ordini di lavoro, agenda, ispezioni e segnalazioni'],
        'works.manage' => ['Gestire i lavori', 'Creare e chiudere ordini, squadre, preventivi, SAL'],
        'users.manage' => ['Gestire utenti e ruoli', 'Creare utenti, assegnare ruoli, impostazioni dello studio'],
        'portal.view' => ['Portale del committente', 'Accesso riservato al proprio Comune o cliente'],
        'impresa.view' => ['Portale delle imprese', 'Accesso riservato ai lavori affidati alla propria squadra'],
    ];

    /**
     * I permessi dei portali non si mescolano con quelli interni: un ruolo
     * di studio con "portale del committente" o un ruolo cliente con il
     * censimento aperto sarebbero due modi diversi di mostrare a qualcuno
     * dati che non sono suoi.
     */
    public const SOLO_PORTALI = ['portal.view', 'impresa.view'];

    /**
     * Ruoli che il programma da' per scontati e che quindi non si
     * rinominano ne' si eliminano: il codice li nomina per decidere il tipo
     * di utente, l'accesso ai portali e la guardia sull'ultimo
     * amministratore.
     */
    public static function ruoliDiSistema(): array
    {
        return array_keys(TenantProvisioner::ROLES);
    }

    /**
     * Il ruolo che non si tocca in nessun modo: senza un amministratore con
     * tutti i permessi ci si chiude fuori da soli, e per rientrare
     * servirebbe una riga di comando sul server.
     */
    public const INTOCCABILE = 'amministratore';

    /** L'elenco completo per la pagina: gruppo, chiave, nome, spiegazione. */
    public static function elenco(): array
    {
        $out = [];
        foreach (self::GRUPPI as $gruppo => $chiavi) {
            foreach ($chiavi as $chiave) {
                [$nome, $spiegazione] = self::ETICHETTE[$chiave] ?? [$chiave, ''];
                $out[] = [
                    'chiave' => $chiave,
                    'gruppo' => $gruppo,
                    'nome' => $nome,
                    'spiegazione' => $spiegazione,
                    'solo_portali' => in_array($chiave, self::SOLO_PORTALI, true),
                ];
            }
        }

        return $out;
    }

    /** Il nome leggibile di un permesso, o la sua chiave se non lo conosciamo. */
    public static function nome(string $chiave): string
    {
        return self::ETICHETTE[$chiave][0] ?? $chiave;
    }
}
