<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Marche temporali secondo la RFC 3161 (Time-Stamp Protocol), scritte e
 * lette a mano in DER.
 *
 * La richiesta porta l'impronta SHA-256 del documento e un numero casuale
 * (nonce); la risposta porta lo stato e, se concessa, il gettone firmato dalla
 * TSA (una struttura CMS SignedData il cui contenuto e' il TSTInfo: politica,
 * impronta, numero di serie, istante certificato, lo stesso nonce). Niente
 * librerie: il protocollo e' piccolo e cosi' si vede tutto quello che parte
 * e che torna. La verifica crittografica della firma la fa openssl
 * (MarcheTemporali::verifica), qui si legge e si controlla la coerenza.
 */
final class Rfc3161
{
    public const OID_SHA256 = '2.16.840.1.101.3.4.2.1';

    public const OID_SIGNED_DATA = '1.2.840.113549.1.7.2';

    public const OID_TST_INFO = '1.2.840.113549.1.9.16.1.4';

    /** PKIStatus (RFC 3161, 2.4.2) */
    public const STATI = [
        0 => 'concessa', 1 => 'concessa con modifiche', 2 => 'rifiutata',
        3 => 'in attesa', 4 => 'avviso di revoca', 5 => 'revocata',
    ];

    /** PKIFailureInfo: posizione del bit => spiegazione */
    public const FALLIMENTI = [
        0 => 'algoritmo di impronta non riconosciuto', 2 => 'richiesta non valida',
        5 => 'formato dei dati errato', 14 => "l'ora della TSA non e' disponibile",
        15 => 'politica di marcatura non accettata', 16 => 'estensione non accettata',
        17 => 'informazione aggiuntiva non disponibile', 25 => 'errore interno della TSA',
    ];

    private const OID_NOMI = ['2.5.4.3' => 'CN', '2.5.4.10' => 'O', '2.5.4.11' => 'OU', '2.5.4.6' => 'C'];

    // ---- Richiesta -----------------------------------------------------------

    /**
     * TimeStampReq: versione 1, impronta SHA-256, politica facoltativa, nonce,
     * richiesta del certificato della TSA dentro la risposta (serve a chi
     * verifica fra anni, quando il sito del fornitore potrebbe non esserci piu').
     */
    public static function richiesta(string $impronta, string $nonce, ?string $policy = null, bool $conCertificato = true): string
    {
        if (strlen($impronta) !== 32) {
            throw new InvalidArgumentException("L'impronta deve essere SHA-256 (32 byte).");
        }
        if ($nonce === '' || strlen($nonce) > 16) {
            throw new InvalidArgumentException('Il nonce deve essere fra 1 e 16 byte.');
        }

        return self::sequenza(
            self::intero("\x01")
            .self::sequenza(self::sequenza(self::oid(self::OID_SHA256).self::nullo()).self::ottetti($impronta))
            .($policy !== null && $policy !== '' ? self::oid($policy) : '')
            .self::intero($nonce)
            .($conCertificato ? self::booleano(true) : '')
        );
    }

    // ---- Risposta ------------------------------------------------------------

    /**
     * Legge una TimeStampResp.
     *
     * @return array{stato:int, stato_testo:string, motivo:?string, fallimento:?string, token:?string, tst:?array, certificati:array<int,string>}
     */
    public static function risposta(string $der): array
    {
        $radice = self::elemento($der);
        if ($radice['tag'] !== 0x30) {
            throw new InvalidArgumentException('La risposta della TSA non e\' una TimeStampResp.');
        }
        $parti = self::figli($radice['contenuto']);
        if ($parti === [] || $parti[0]['tag'] !== 0x30) {
            throw new InvalidArgumentException('La risposta della TSA non porta lo stato.');
        }
        $statoInfo = self::figli($parti[0]['contenuto']);
        $stato = self::interoPiccolo($statoInfo[0]['contenuto'] ?? '');
        $motivo = null;
        $fallimento = null;
        foreach (array_slice($statoInfo, 1) as $voce) {
            if ($voce['tag'] === 0x30) {
                // PKIFreeText: una o piu' righe UTF-8 scritte dalla TSA
                $motivo = trim(implode(' ', array_map(fn ($t) => $t['contenuto'], self::figli($voce['contenuto']))));
            } elseif ($voce['tag'] === 0x03) {
                $fallimento = self::fallimento($voce['contenuto']);
            }
        }

        $esito = [
            'stato' => $stato,
            'stato_testo' => self::STATI[$stato] ?? "stato {$stato}",
            'motivo' => $motivo !== '' ? $motivo : null,
            'fallimento' => $fallimento,
            'token' => null,
            'tst' => null,
            'certificati' => [],
        ];
        if (isset($parti[1]) && $parti[1]['tag'] === 0x30) {
            $gettone = self::gettone($parti[1]['raw']);
            $esito['token'] = $parti[1]['raw'];
            $esito['tst'] = $gettone['tst'];
            $esito['certificati'] = $gettone['certificati'];
        }

        return $esito;
    }

    /**
     * Il gettone: ContentInfo(signedData) > SignedData > TSTInfo, piu' i
     * certificati allegati.
     *
     * @return array{tst:array, certificati:array<int,string>}
     */
    public static function gettone(string $token): array
    {
        $contentInfo = self::figli(self::elemento($token)['contenuto']);
        if (count($contentInfo) < 2 || self::oidTesto($contentInfo[0]['contenuto']) !== self::OID_SIGNED_DATA) {
            throw new InvalidArgumentException('Il gettone della TSA non e\' un CMS SignedData.');
        }
        $signedData = self::figli(self::elemento($contentInfo[1]['contenuto'])['contenuto']);
        if (count($signedData) < 3) {
            throw new InvalidArgumentException('Il gettone della TSA e\' incompleto.');
        }
        $encap = self::figli($signedData[2]['contenuto']);
        if (count($encap) < 2 || self::oidTesto($encap[0]['contenuto']) !== self::OID_TST_INFO) {
            throw new InvalidArgumentException('Il gettone della TSA non contiene un TSTInfo.');
        }
        $tstInfo = self::elemento($encap[1]['contenuto']);

        $certificati = [];
        foreach (array_slice($signedData, 3) as $parte) {
            if ($parte['tag'] === 0xA0) {
                foreach (self::figli($parte['contenuto']) as $cert) {
                    $certificati[] = $cert['raw'];
                }
            }
        }

        return ['tst' => self::tstInfo($tstInfo['contenuto']), 'certificati' => $certificati];
    }

    /** @return array{versione:int, policy:string, algoritmo:string, impronta:string, seriale:string, generato_il:CarbonImmutable, precisione_s:?float, nonce:?string, tsa:?string} */
    private static function tstInfo(string $ottetti): array
    {
        // Dentro l'OCTET STRING c'e' la SEQUENCE del TSTInfo
        $campi = self::figli(self::elemento($ottetti)['contenuto']);
        if (count($campi) < 5) {
            throw new InvalidArgumentException('Il TSTInfo della marca e\' incompleto.');
        }
        $imprint = self::figli($campi[2]['contenuto']);
        $algoritmo = self::figli($imprint[0]['contenuto'] ?? '');

        $tst = [
            'versione' => self::interoPiccolo($campi[0]['contenuto']),
            'policy' => self::oidTesto($campi[1]['contenuto']),
            'algoritmo' => self::oidTesto($algoritmo[0]['contenuto'] ?? ''),
            'impronta' => bin2hex($imprint[1]['contenuto'] ?? ''),
            'seriale' => self::esadecimale($campi[3]['contenuto']),
            'generato_il' => self::generalizedTime($campi[4]['contenuto']),
            'precisione_s' => null,
            'nonce' => null,
            'tsa' => null,
        ];
        foreach (array_slice($campi, 5) as $voce) {
            if ($voce['tag'] === 0x02) {
                $tst['nonce'] = self::esadecimale($voce['contenuto']);
            } elseif ($voce['tag'] === 0xA0) {
                $tst['tsa'] = self::nomeGenerale($voce['contenuto']);
            } elseif ($voce['tag'] === 0x30) {
                $tst['precisione_s'] = self::precisione($voce['contenuto']);
            }
        }

        return $tst;
    }

    /**
     * Chi ha firmato, letto dal certificato allegato che ha lo scopo di
     * marcatura temporale (o dal primo, se lo scopo non e' dichiarato).
     *
     * @param  array<int,string>  $certificati  DER
     * @return array{nome:?string, organizzazione:?string, emittente:?string, scade_il:?CarbonImmutable}|null
     */
    public static function firmatario(array $certificati): ?array
    {
        $scelto = null;
        foreach ($certificati as $der) {
            $dati = @openssl_x509_parse(self::pem($der));
            if (! is_array($dati)) {
                continue;
            }
            $scelto ??= $dati;
            if (str_contains((string) ($dati['extensions']['extendedKeyUsage'] ?? ''), 'Time Stamping')) {
                $scelto = $dati;
                break;
            }
        }
        if ($scelto === null) {
            return null;
        }

        return [
            'nome' => $scelto['subject']['CN'] ?? null,
            'organizzazione' => $scelto['subject']['O'] ?? null,
            'emittente' => $scelto['issuer']['CN'] ?? ($scelto['issuer']['O'] ?? null),
            'scade_il' => isset($scelto['validTo_time_t']) ? CarbonImmutable::createFromTimestampUTC($scelto['validTo_time_t']) : null,
        ];
    }

    /** Rappresentazione esadecimale di un INTEGER DER senza zeri iniziali (per seriale e nonce). */
    public static function esadecimale(string $bin): string
    {
        $hex = ltrim(bin2hex($bin), '0');

        return $hex === '' ? '0' : $hex;
    }

    // ---- Scrittura DER -------------------------------------------------------

    public static function tlv(int $tag, string $contenuto): string
    {
        return chr($tag).self::lunghezza(strlen($contenuto)).$contenuto;
    }

    public static function sequenza(string $contenuto): string
    {
        return self::tlv(0x30, $contenuto);
    }

    public static function ottetti(string $contenuto): string
    {
        return self::tlv(0x04, $contenuto);
    }

    public static function utf8(string $testo): string
    {
        return self::tlv(0x0C, $testo);
    }

    public static function nullo(): string
    {
        return "\x05\x00";
    }

    public static function booleano(bool $valore): string
    {
        return self::tlv(0x01, $valore ? "\xFF" : "\x00");
    }

    /** INTEGER positivo da byte grezzi (big endian). */
    public static function intero(string $bin): string
    {
        $bin = ltrim($bin, "\0");
        if ($bin === '') {
            $bin = "\0";
        }
        if (ord($bin[0]) & 0x80) {
            $bin = "\0".$bin;
        }

        return self::tlv(0x02, $bin);
    }

    /** BIT STRING dai bit accesi (posizione 0 = primo bit). */
    public static function bit(array $posizioni): string
    {
        if ($posizioni === []) {
            return self::tlv(0x03, "\0");
        }
        $byte = array_fill(0, intdiv(max($posizioni), 8) + 1, 0);
        foreach ($posizioni as $p) {
            $byte[intdiv($p, 8)] |= 0x80 >> ($p % 8);
        }
        $inutilizzati = (8 - ((max($posizioni) + 1) % 8)) % 8;

        return self::tlv(0x03, chr($inutilizzati).implode('', array_map('chr', $byte)));
    }

    public static function oid(string $punti): string
    {
        $parti = array_map('intval', explode('.', $punti));
        if (count($parti) < 2 || $parti[0] > 2 || ($parti[0] < 2 && $parti[1] > 39)) {
            throw new InvalidArgumentException("OID non valido: {$punti}");
        }
        $out = self::base128(40 * $parti[0] + $parti[1]);
        foreach (array_slice($parti, 2) as $n) {
            $out .= self::base128($n);
        }

        return self::tlv(0x06, $out);
    }

    private static function base128(int $n): string
    {
        $byte = [$n & 0x7F];
        while (($n >>= 7) > 0) {
            array_unshift($byte, ($n & 0x7F) | 0x80);
        }

        return implode('', array_map('chr', $byte));
    }

    private static function lunghezza(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $byte = ltrim(pack('N', $n), "\0");

        return chr(0x80 | strlen($byte)).$byte;
    }

    // ---- Lettura DER ---------------------------------------------------------

    /** @return array{tag:int, contenuto:string, raw:string, fine:int} */
    public static function elemento(string $der, int $posizione = 0): array
    {
        $lunghezzaTotale = strlen($der);
        if ($posizione + 2 > $lunghezzaTotale) {
            throw new InvalidArgumentException('DER troncato.');
        }
        $inizio = $posizione;
        $tag = ord($der[$posizione++]);
        if (($tag & 0x1F) === 0x1F) {
            throw new InvalidArgumentException('Tag DER esteso non gestito.');
        }
        $lunghezza = ord($der[$posizione++]);
        if ($lunghezza & 0x80) {
            $byte = $lunghezza & 0x7F;
            if ($byte === 0 || $byte > 4 || $posizione + $byte > $lunghezzaTotale) {
                throw new InvalidArgumentException('Lunghezza DER non valida.');
            }
            $lunghezza = 0;
            for ($i = 0; $i < $byte; $i++) {
                $lunghezza = ($lunghezza << 8) | ord($der[$posizione++]);
            }
        }
        if ($posizione + $lunghezza > $lunghezzaTotale) {
            throw new InvalidArgumentException('DER troncato.');
        }

        return [
            'tag' => $tag,
            'contenuto' => substr($der, $posizione, $lunghezza),
            'raw' => substr($der, $inizio, $posizione + $lunghezza - $inizio),
            'fine' => $posizione + $lunghezza,
        ];
    }

    /** @return array<int, array{tag:int, contenuto:string, raw:string, fine:int}> */
    public static function figli(string $der): array
    {
        $figli = [];
        $posizione = 0;
        $lunghezza = strlen($der);
        while ($posizione < $lunghezza) {
            $e = self::elemento($der, $posizione);
            $figli[] = $e;
            $posizione = $e['fine'];
        }

        return $figli;
    }

    public static function oidTesto(string $contenuto): string
    {
        if ($contenuto === '') {
            return '';
        }
        $valori = [];
        $n = 0;
        for ($i = 0, $l = strlen($contenuto); $i < $l; $i++) {
            $b = ord($contenuto[$i]);
            $n = ($n << 7) | ($b & 0x7F);
            if (! ($b & 0x80)) {
                $valori[] = $n;
                $n = 0;
            }
        }
        $primo = array_shift($valori);
        $arco = $primo >= 80 ? 2 : intdiv($primo, 40);

        return implode('.', [$arco, $primo - 40 * $arco, ...$valori]);
    }

    private static function interoPiccolo(string $contenuto): int
    {
        $v = 0;
        foreach (str_split(substr($contenuto, -4) ?: "\0") as $b) {
            $v = ($v << 8) | ord($b);
        }

        return $v;
    }

    /** GeneralizedTime della marca: sempre in UTC (RFC 3161 vuole la Z), con eventuali frazioni. */
    public static function generalizedTime(string $testo): CarbonImmutable
    {
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})?(?:[.,](\d+))?Z$/', $testo, $m)) {
            throw new InvalidArgumentException("Istante della marca non leggibile: {$testo}");
        }
        $istante = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), 'UTC');
        if (isset($m[7]) && $m[7] !== '') {
            $istante = $istante->setMicroseconds((int) str_pad(substr($m[7], 0, 6), 6, '0'));
        }

        return $istante;
    }

    private static function precisione(string $contenuto): ?float
    {
        $secondi = 0.0;
        foreach (self::figli($contenuto) as $voce) {
            $valore = self::interoPiccolo($voce['contenuto']);
            $secondi += match ($voce['tag']) {
                0x02 => $valore,
                0x80 => $valore / 1000,
                0x81 => $valore / 1_000_000,
                default => 0,
            };
        }

        return $secondi;
    }

    /** GeneralName [0] EXPLICIT: il nome della TSA, di solito un directoryName. */
    private static function nomeGenerale(string $contenuto): ?string
    {
        $interno = self::figli($contenuto)[0] ?? null;
        if ($interno === null) {
            return null;
        }

        return match ($interno['tag']) {
            0xA4 => self::nomeDistinto(self::elemento($interno['contenuto'])['contenuto']),
            0x81, 0x82, 0x86 => $interno['contenuto'],
            default => null,
        };
    }

    /** Name (SEQUENCE OF RDN): si tiene "CN (O)". */
    private static function nomeDistinto(string $contenuto): ?string
    {
        $attributi = [];
        foreach (self::figli($contenuto) as $rdn) {
            foreach (self::figli($rdn['contenuto']) as $coppia) {
                $campi = self::figli($coppia['contenuto']);
                if (count($campi) === 2) {
                    $attributi[self::OID_NOMI[self::oidTesto($campi[0]['contenuto'])] ?? self::oidTesto($campi[0]['contenuto'])] = $campi[1]['contenuto'];
                }
            }
        }
        $nome = $attributi['CN'] ?? $attributi['O'] ?? null;
        if ($nome !== null && isset($attributi['O']) && $attributi['O'] !== $nome) {
            $nome .= ' ('.$attributi['O'].')';
        }

        return $nome;
    }

    private static function fallimento(string $bitString): ?string
    {
        $inutilizzati = ord($bitString[0] ?? "\0");
        $byte = substr($bitString, 1);
        $trovati = [];
        for ($i = 0, $bits = strlen($byte) * 8 - $inutilizzati; $i < $bits; $i++) {
            if (ord($byte[intdiv($i, 8)]) & (0x80 >> ($i % 8))) {
                $trovati[] = self::FALLIMENTI[$i] ?? "codice {$i}";
            }
        }

        return $trovati ? implode(', ', $trovati) : null;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }
}
