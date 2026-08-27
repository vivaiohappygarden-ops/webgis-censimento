<?php

namespace App\Services\Import;

/**
 * Lettura dei valori grezzi che arrivano dai file importati (DBF, GeoJSON,
 * fogli esportati da altri programmi): numeri con la virgola, date in vari
 * formati, testo con byte NUL. Regole scritte una volta sola: le usano
 * l'import CAM e l'import generico con mappatura delle colonne.
 */
class LetturaValori
{
    /** Testo ripulito (NUL via, spazi ai bordi via); null se vuoto. */
    public static function testo(mixed $value): ?string
    {
        // Il GeoJSON ammette valori annidati (liste, oggetti): non sono testo
        // e il cast a stringa di un array manderebbe in errore la richiesta
        if ($value !== null && ! is_scalar($value)) {
            return null;
        }
        // I byte NUL non sono rappresentabili in jsonb: scoprirlo
        // all'insert romperebbe il contratto dry-run/import
        $v = trim(str_replace("\0", '', (string) ($value ?? '')));

        return $v !== '' ? $v : null;
    }

    /** Numero (virgola o punto); null se vuoto o non numerico (con avviso). */
    public static function numero(mixed $value, string $context, array &$warnings): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value)) {
            $warnings[] = "{$context}: valore composto (lista o oggetto), ignorato.";

            return null;
        }
        $normalized = str_replace(',', '.', (string) $value);
        // is_finite: '1e999' passa is_numeric ma diventa INF, che jsonb
        // non sa rappresentare (json_encode fallirebbe silenziosamente)
        if (! is_numeric($normalized) || ! is_finite((float) $normalized)) {
            $warnings[] = "{$context}: valore '{$value}' non numerico, ignorato.";

            return null;
        }

        return (float) $normalized;
    }

    /** Data GGMMAAAA dei tracciati record MD -> data ISO; null se non lo è. */
    public static function dataCam(mixed $value): ?string
    {
        if ($value !== null && ! is_scalar($value)) {
            return null;
        }
        $value = trim((string) ($value ?? ''));
        if (! preg_match('/^(\d{2})(\d{2})(\d{4})$/', $value, $m)) {
            return null;
        }
        if (! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
    }

    /**
     * Data nei formati che si incontrano davvero nei file (GGMMAAAA dei
     * tracciati, AAAA/MM/GG e AAAA-MM-GG dei DBF convertiti, GG/MM/AAAA
     * all'italiana); un valore non riconosciuto viene segnalato, non perso
     * in silenzio.
     */
    public static function data(mixed $value, string $context, array &$warnings): ?string
    {
        if ($value !== null && ! is_scalar($value)) {
            $warnings[] = "{$context}: valore composto (lista o oggetto), ignorato.";

            return null;
        }
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (($cam = self::dataCam($raw)) !== null) {
            return $cam;
        }
        foreach (['Y/m/d', 'Y-m-d', 'd/m/Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $raw);
            if ($parsed !== false && $parsed->format($format) === $raw) {
                return $parsed->format('Y-m-d');
            }
        }
        $warnings[] = "{$context}: data '{$raw}' non riconosciuta, ignorata.";

        return null;
    }
}
