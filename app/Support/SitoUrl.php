<?php

namespace App\Support;

/**
 * Indirizzi interni del sito aziendale.
 *
 * Le stesse pagine rispondono da due parti - il dominio nudo e il percorso
 * di collaudo /sito - e i collegamenti devono restare dentro la strada da
 * cui si e' arrivati: chi sta guardando /sito/censimento non deve trovarsi
 * sbalzato sul dominio pubblico, e viceversa. Stessa soluzione dei portali
 * civici (PortalContext::url).
 */
final class SitoUrl
{
    public static function per(string $pagina = ''): string
    {
        $prefisso = request()->routeIs('sito.percorso.*') ? '/sito' : '';
        $pagina = trim($pagina, '/');

        return $pagina === '' ? ($prefisso ?: '/') : $prefisso.'/'.$pagina;
    }
}
