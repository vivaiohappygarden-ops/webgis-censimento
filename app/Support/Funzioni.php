<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Le funzioni che la console della piattaforma accende o spegne per ogni
 * organizzazione (organizations.settings['funzioni']). Il collegamento al
 * gestionale giardini (WordPress) e' nostro, non del prodotto: chi affitta il
 * programma lo trova spento, e lo si accende solo a chi lo deve avere.
 */
final class Funzioni
{
    public const GESTIONALE_GIARDINI = 'gestionale_giardini';

    /** Le funzioni regolabili e il loro valore di serie per un'organizzazione nuova. */
    public const DI_SERIE = [
        self::GESTIONALE_GIARDINI => false,
    ];

    /** @return array<string, bool> lo stato di tutte le funzioni per l'organizzazione */
    public static function per(Organization|string|null $organizzazione): array
    {
        $o = $organizzazione instanceof Organization ? $organizzazione : ($organizzazione ? Organization::query()->find($organizzazione) : null);
        $impostate = $o?->settings['funzioni'] ?? [];
        $stato = [];
        foreach (self::DI_SERIE as $funzione => $diSerie) {
            $stato[$funzione] = (bool) ($impostate[$funzione] ?? $diSerie);
        }

        return $stato;
    }

    public static function attiva(Organization|string|null $organizzazione, string $funzione): bool
    {
        return self::per($organizzazione)[$funzione] ?? false;
    }
}
