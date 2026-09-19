<?php

namespace App\Support;

/**
 * Indirizzi del sito aziendale: collegamenti interni, canonical, risorse.
 *
 * Le stesse pagine rispondono da due parti - il dominio nudo e il percorso di
 * collaudo /sito - e i collegamenti devono restare dentro la strada da cui si
 * e' arrivati: chi sta guardando /sito/censimento non deve trovarsi sbalzato
 * sul dominio pubblico, e viceversa. Stessa soluzione dei portali civici
 * (PortalContext::url). Nessuna vista scrive "/sito" a mano: passa da qui.
 */
final class SitoUrl
{
    /** Le pagine e il loro indirizzo pulito (chiave = slug; la home e' la radice). */
    public const PAGINE = ['home', 'censimento', 'stabilita-vta', 'portale-cittadini', 'conformita-cam', 'chi-siamo', 'contatti', 'privacy'];

    /** Vero quando la pagina e' stata aperta dal percorso di collaudo /sito. */
    public static function inCollaudo(): bool
    {
        return request()->routeIs('sito.percorso.*');
    }

    /** Collegamento interno relativo, nella strada da cui si e' arrivati. */
    public static function per(string $pagina = ''): string
    {
        $prefisso = self::inCollaudo() ? '/sito' : '';
        $pagina = trim($pagina, '/');
        if ($pagina === 'home') {
            $pagina = '';
        }

        return $pagina === '' ? ($prefisso ?: '/') : $prefisso.'/'.$pagina;
    }

    /**
     * Una risorsa statica del sito (public/sito-risorse/…): stesso percorso su
     * dominio e collaudo. La cartella non si chiama "sito" apposta: un
     * percorso che esiste su disco coprirebbe il percorso di collaudo /sito.
     */
    public static function risorsa(string $percorso): string
    {
        return '/sito-risorse/'.ltrim($percorso, '/');
    }

    /** Il dominio di produzione, o null finche' non e' configurato. */
    public static function dominio(): ?string
    {
        return SitoDati::testo('base_host');
    }

    /** Indirizzo assoluto sul dominio di produzione, o null se il dominio non c'e'. */
    public static function assoluto(string $pagina = ''): ?string
    {
        $dominio = self::dominio();
        if ($dominio === null) {
            return null;
        }
        $pagina = trim($pagina, '/');
        if ($pagina === 'home') {
            $pagina = '';
        }

        return 'https://'.$dominio.($pagina === '' ? '/' : '/'.$pagina);
    }

    /**
     * La canonical della pagina: solo sul dominio di produzione. Dal percorso
     * di collaudo non se ne emette nessuna (le pagine sono noindex, e una
     * canonical accanto a un noindex e' un segnale contraddittorio), e senza
     * dominio non si punta a un indirizzo che non esiste.
     */
    public static function canonica(string $pagina): ?string
    {
        return self::inCollaudo() ? null : self::assoluto($pagina);
    }

    /** Vero quando la pagina puo' essere indicizzata: dominio configurato e non in collaudo. */
    public static function indicizzabile(): bool
    {
        return self::dominio() !== null && ! self::inCollaudo();
    }
}
