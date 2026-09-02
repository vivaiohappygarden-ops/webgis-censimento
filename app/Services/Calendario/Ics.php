<?php

namespace App\Services\Calendario;

use Illuminate\Support\Carbon;

/**
 * Scrittura del formato iCalendar (RFC 5545) a mano: è testo, una libreria
 * non serve. Qui vive solo la forma (escaping, piegatura delle righe, CRLF);
 * il contenuto degli eventi sta in CalendarioAbbonamento.
 *
 * Tutti gli eventi sono di giornata intera (VALUE=DATE): i lavori si
 * pianificano per giorni e le scadenze sono date pure. Così non serve un
 * VTIMEZONE: una data senza ora è la stessa in ogni fuso, ed è la forma che
 * Google Calendar, iPhone e Outlook interpretano senza ambiguità.
 */
final class Ics
{
    /**
     * Un VEVENT di giornata intera. $fine è inclusiva (l'ultimo giorno del
     * lavoro): DTEND per RFC è esclusivo, quindi si somma un giorno.
     *
     * @param  array{uid: string, inizio: string, fine?: string|null, titolo: string, descrizione?: string|null}  $evento
     */
    public static function evento(array $evento, string $dtstamp): string
    {
        $inizio = Carbon::parse($evento['inizio']);
        $fine = Carbon::parse($evento['fine'] ?? $evento['inizio'])->addDay();

        $righe = [
            'BEGIN:VEVENT',
            'UID:'.self::escape($evento['uid']),
            'DTSTAMP:'.$dtstamp,
            'DTSTART;VALUE=DATE:'.$inizio->format('Ymd'),
            'DTEND;VALUE=DATE:'.$fine->format('Ymd'),
            'SUMMARY:'.self::escape($evento['titolo']),
        ];
        if (($evento['descrizione'] ?? '') !== '') {
            $righe[] = 'DESCRIPTION:'.self::escape($evento['descrizione']);
        }
        $righe[] = 'END:VEVENT';

        return implode('', array_map(self::riga(...), $righe));
    }

    /** @param  list<string>  $eventi  blocchi VEVENT già composti */
    public static function calendario(array $eventi, string $nome): string
    {
        $testa = array_map(self::riga(...), [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//WebGIS Verde//Calendario//IT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($nome),
        ]);

        return implode('', $testa).implode('', $eventi).self::riga('END:VCALENDAR');
    }

    /**
     * Escaping dei valori di testo (RFC 5545 par. 3.3.11): backslash, punto e
     * virgola e virgola si proteggono, gli a capo diventano "\n" letterale.
     * Senza, una virgola nel titolo di un lavoro spezzerebbe il campo.
     */
    public static function escape(string $testo): string
    {
        return str_replace(
            ["\\", ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $testo,
        );
    }

    /**
     * Una riga di contenuto, piegata a non più di 75 ottetti e chiusa da CRLF.
     * Le continuazioni iniziano con uno spazio (che conta nei 75). Si piega
     * contando i byte ma spezzando fra un carattere UTF-8 e l'altro: un
     * accento tagliato a metà renderebbe il file illeggibile.
     */
    public static function riga(string $contenuto): string
    {
        $pezzi = [];
        $corrente = '';
        $byteCorrenti = 0;
        // Il primo segmento ha 75 ottetti pieni; i successivi 74, perché lo
        // spazio di continuazione occupa il primo
        $limite = 75;

        foreach (mb_str_split($contenuto, 1, 'UTF-8') as $carattere) {
            $byte = strlen($carattere);
            if ($byteCorrenti + $byte > $limite) {
                $pezzi[] = $corrente;
                $corrente = '';
                $byteCorrenti = 0;
                $limite = 74;
            }
            $corrente .= $carattere;
            $byteCorrenti += $byte;
        }
        $pezzi[] = $corrente;

        return implode("\r\n ", $pezzi)."\r\n";
    }
}
