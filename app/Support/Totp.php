<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Codici a tempo (TOTP, RFC 6238 su HMAC-SHA1, RFC 4226): gli stessi che
 * generano Google Authenticator, Microsoft Authenticator, FreeOTP, Aegis e
 * simili. Sei cifre ogni trenta secondi, segreto in base32. Nessuna
 * dipendenza: sono poche righe, e cosi' restano leggibili e verificabili
 * con i vettori di prova della RFC (DueFattoriTest).
 */
class Totp
{
    public const PERIODO = 30;

    public const CIFRE = 6;

    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Un segreto nuovo: 20 byte casuali (160 bit, quanto chiede la RFC 4226), in base32. */
    public static function segreto(): string
    {
        return self::base32(random_bytes(20));
    }

    /** Il passo temporale: quanti intervalli da trenta secondi sono passati dall'epoca. */
    public static function passo(?int $timestamp = null): int
    {
        // Carbon e non time(): nelle prove il tempo si sposta con travel()
        return intdiv($timestamp ?? Carbon::now()->getTimestamp(), self::PERIODO);
    }

    /** Il codice di un dato passo. */
    public static function codice(string $segreto, int $passo): string
    {
        $hmac = hash_hmac('sha1', pack('J', $passo), self::decodifica($segreto), true);
        $offset = ord($hmac[19]) & 0x0F;
        $numero = ((ord($hmac[$offset]) & 0x7F) << 24)
            | (ord($hmac[$offset + 1]) << 16)
            | (ord($hmac[$offset + 2]) << 8)
            | ord($hmac[$offset + 3]);

        return str_pad((string) ($numero % (10 ** self::CIFRE)), self::CIFRE, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica un codice nella finestra di +/- $finestra passi (l'orologio del
     * telefono puo' essere avanti o indietro di qualche secondo). Restituisce
     * il passo che ha combaciato, o null. Con $dopoIlPasso si rifiutano i
     * passi gia' spesi: la RFC vuole che ogni codice valga una volta sola.
     */
    public static function verifica(string $segreto, string $codice, int $finestra = 1, ?int $dopoIlPasso = null, ?int $timestamp = null): ?int
    {
        $codice = preg_replace('/\D/', '', $codice);
        if (strlen($codice) !== self::CIFRE) {
            return null;
        }

        $adesso = self::passo($timestamp);
        for ($delta = -$finestra; $delta <= $finestra; $delta++) {
            $passo = $adesso + $delta;
            if ($dopoIlPasso !== null && $passo <= $dopoIlPasso) {
                continue;
            }
            if (hash_equals(self::codice($segreto, $passo), $codice)) {
                return $passo;
            }
        }

        return null;
    }

    /** L'indirizzo otpauth:// che l'app legge dal codice QR (o che si scrive a mano con il segreto). */
    public static function uri(string $segreto, string $emittente, string $account): string
    {
        return 'otpauth://totp/'.rawurlencode($emittente).':'.rawurlencode($account)
            .'?secret='.$segreto
            .'&issuer='.rawurlencode($emittente)
            .'&algorithm=SHA1&digits='.self::CIFRE.'&period='.self::PERIODO;
    }

    /** Base32 (RFC 4648) senza riempimento: l'alfabeto che le app di autenticazione si aspettano. */
    public static function base32(string $byte): string
    {
        $bit = '';
        foreach (str_split($byte) as $carattere) {
            $bit .= str_pad(decbin(ord($carattere)), 8, '0', STR_PAD_LEFT);
        }

        $testo = '';
        foreach (str_split($bit, 5) as $gruppo) {
            $testo .= self::ALFABETO[bindec(str_pad($gruppo, 5, '0'))];
        }

        return $testo;
    }

    public static function decodifica(string $base32): string
    {
        $base32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32));
        $bit = '';
        foreach (str_split($base32) as $carattere) {
            $bit .= str_pad(decbin((int) strpos(self::ALFABETO, $carattere)), 5, '0', STR_PAD_LEFT);
        }

        $byte = '';
        foreach (str_split($bit, 8) as $ottetto) {
            if (strlen($ottetto) === 8) {
                $byte .= chr(bindec($ottetto));
            }
        }

        return $byte;
    }
}
