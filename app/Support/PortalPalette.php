<?php

namespace App\Support;

/**
 * Tavolozza del portale pubblico ricavata dal solo colore scelto dal Comune.
 *
 * Perché in PHP e non con color-mix() direttamente nel foglio di stile: negli
 * uffici comunali e sui telefoni non aggiornati girano ancora browser che non
 * conoscono color-mix(); lì la dichiarazione viene buttata via e la pagina
 * perde i colori, cioè perde i contrasti. Calcolando qui, il portale esce con
 * valori esadecimali pieni che qualunque browser capisce, il risultato è
 * identico ovunque e — non ultimo — si può collaudare (PortalPaletteTest).
 *
 * La miscela avviene in oklab, come nelle bozze approvate: è uno spazio
 * percettivamente uniforme, quindi mescolare un verde scuro con un avorio non
 * passa dal grigio fangoso che darebbe la stessa operazione fatta in sRGB.
 *
 * Il colore di partenza lo scrive un umano nel gestionale: qui dentro non
 * deve poter esplodere niente. Un colore vuoto, senza cancelletto, a tre
 * cifre o sporco ripiega sul verde di serie.
 *
 * Ultima cosa, la più importante: la tinta la sceglie il Comune, ma la
 * leggibilità non è negoziabile. Dopo la miscela le GUARDIE ricontrollano i
 * rapporti di contrasto WCAG delle coppie che portano testo e scuriscono (o
 * schiariscono) quel tanto che basta. Con una tinta chiarissima il portale
 * perde un po' di tinta, mai il testo.
 */
final class PortalPalette
{
    /** Verde di serie: lo stesso ripiego di PortalContext::color(). */
    public const TINTA_PREDEFINITA = '#14532d';

    /**
     * Le ricette della tavolozza: token => [sorgente, quota della sorgente in
     * centesimi, colore di base]. Le percentuali vengono dalle bozze
     * approvate (il blocco <style> di Chioma.dc.html e ChiomaScheda.dc.html):
     * "56%" significa 56 parti di sorgente e 44 di base, mescolate in oklab,
     * esattamente come color-mix(in oklab, var(--tinta) 56%, #050d09).
     *
     * La sorgente è quasi sempre la tinta; l'oro scuro nasce dall'oro (così
     * resta oro anche quando la tinta è un rosso mattone) e le due cortecce
     * non nascono da niente: nessuna corteccia è verde, e se la tinta fosse
     * un viola gli alberi disegnati diventerebbero di plastica.
     */
    private const RICETTE = [
        // --- notte e bosco: i fondi scuri di testata, copertina e piede ----
        'notte' => ['tinta', 56, '#050d09'],
        'notte-fondo' => ['tinta', 32, '#030806'],
        'bosco' => ['tinta', 88, '#0b1a12'],

        // --- i tre piani del fogliame disegnato: prospettiva aerea, cioè
        //     luminanza crescente e croma calante man mano che si allontana --
        'fogliame-vicino' => ['tinta', 68, '#16281c'],
        'fogliame-medio' => ['tinta', 50, '#a8bda0'],
        'fogliame-lontano' => ['tinta', 26, '#c9d6c4'],

        // --- la luce del sole, una sola in tutto il portale ----------------
        'luce' => ['tinta', 10, '#f9e8bf'],
        'luce-2' => ['tinta', 40, '#e5cb92'],

        // --- accento unico: oro -------------------------------------------
        'oro' => ['tinta', 12, '#c2933a'],
        'oro-scuro' => ['oro', 46, '#2e2207'],
        'oro-chiaro' => ['tinta', 8, '#eed7a2'],

        // --- il corpo chiaro delle pagine ---------------------------------
        'avorio' => ['tinta', 4, '#faf5ea'],
        'avorio-2' => ['tinta', 7, '#f2ebd9'],
        'carta' => ['tinta', 2, '#fdfbf5'],
        'chiaro' => ['tinta', 8, '#f6f0e3'],
        'chiaro-2' => ['tinta', 26, '#ded8c6'],
        'inchiostro' => ['tinta', 62, '#14170f'],
        'inchiostro-2' => ['tinta', 44, '#6c7163'],
        'filo' => ['tinta', 15, '#dad1ba'],
        'filo-2' => ['tinta', 34, '#b3a68a'],

        // --- corteccia: sganciata dalla tinta ------------------------------
        'corteccia' => [null, 0, '#6c5c48'],
        'corteccia-2' => [null, 0, '#3b3126'],

        // --- la carta del censimento disegnata (fondali di home e mappa) ---
        'm-fondo' => ['tinta', 9, '#e8dfd0'],
        'm-iso' => ['tinta', 16, '#dad0bc'],
        'm-casa' => ['tinta', 30, '#beb28e'],
        'm-orlo' => ['tinta', 20, '#cbc1a8'],
        'm-parco' => ['tinta', 22, '#dbe3c2'],
        'm-acqua' => ['tinta', 22, '#b6d1d6'],
        'm-testo' => ['tinta', 58, '#262a21'],
    ];

    /**
     * Le coppie che portano testo, con il rapporto minimo che devono
     * rispettare. Si legge: "token, verso della correzione, fondo (o testo)
     * di riferimento, rapporto minimo".
     *
     * L'ordine conta: 'oro-chiaro' si misura sulla notte, quindi la notte
     * dev'essere già stata sistemata.
     */
    private const GUARDIE = [
        // Testo corrente sul fondo di pagina: si punta al livello AAA, perché
        // il portale si legge anche al sole, in strada, davanti alla pianta.
        ['inchiostro', 'scurisci', 'avorio', 7.0],
        // Testo secondario, note, didascalie: AA pieno.
        ['inchiostro-2', 'scurisci', 'avorio', 4.5],
        // I titoli sono grandi (3:1 basterebbe) ma il bosco tinge anche testi
        // di misura normale: si tiene 4,5:1 e non ci si pensa più.
        ['bosco', 'scurisci', 'avorio', 4.5],
        // Gli occhielli in oro sono maiuscoletto piccolo: testo normale.
        ['oro-scuro', 'scurisci', 'avorio', 4.5],
        // Le scritte sulla carta disegnata stanno sopra il verde del parco.
        ['m-testo', 'scurisci', 'm-parco', 4.5],
        // Il filo forte cinge i campi da compilare: è un componente
        // d'interfaccia, gli basta 3:1.
        ['filo-2', 'scurisci', 'avorio', 3.0],
        // Sui fondi scuri si corregge il FONDO, non il testo: il chiaro è già
        // quasi bianco, non c'è più niente da schiarire.
        ['notte', 'scurisci', 'chiaro', 4.5],
        ['notte-fondo', 'scurisci', 'chiaro', 4.5],
        ['oro-chiaro', 'schiarisci', 'notte', 4.5],
    ];

    /** @param  array<string,string>  $token */
    private function __construct(
        public readonly string $tinta,
        private readonly array $token,
    ) {}

    /** Costruisce la tavolozza dal colore del committente. */
    public static function da(?string $tinta): self
    {
        $tinta = self::normalizzaColore($tinta);
        $token = [];

        foreach (self::RICETTE as $nome => [$sorgente, $quota, $base]) {
            $token[$nome] = $sorgente === null
                ? $base
                : self::mescola($token[$sorgente] ?? $tinta, $base, $quota / 100);
        }

        foreach (self::GUARDIE as [$nome, $verso, $riferimento, $minimo]) {
            $token[$nome] = self::garantisci($token[$nome], $token[$riferimento], $minimo, $verso);
        }

        return new self($tinta, $token);
    }

    /** @return array<string,string> tutti i token, nome => #rrggbb */
    public function token(): array
    {
        return $this->token;
    }

    /** Un solo token; un nome sconosciuto è un errore di programmazione. */
    public function colore(string $nome): string
    {
        return $this->token[$nome] ?? throw new \InvalidArgumentException("Token di tavolozza sconosciuto: {$nome}");
    }

    /**
     * Le righe pronte da incollare dentro :root, una per token.
     * Il rientro si passa perché nel layout il blocco è annidato nel <style>.
     */
    public function righeCss(string $rientro = '        '): string
    {
        $righe = [];
        foreach ($this->token as $nome => $valore) {
            $righe[] = $rientro.'--'.$nome.': '.$valore.';';
        }

        return implode("\n", $righe);
    }

    /**
     * Il testo sopra un fondo dev'essere chiaro o scuro? Si guarda il
     * rapporto di contrasto WCAG di entrambe le ipotesi e vince la migliore.
     * Serve a chi disegna sopra un colore che non conosce in anticipo: lo
     * stato della pianta, la tinta del Comune, una targhetta colorata.
     *
     * @return string 'chiaro' oppure 'scuro'
     */
    public static function testoSopra(string $fondo): string
    {
        $fondo = self::normalizzaColore($fondo);

        return self::contrasto('#ffffff', $fondo) >= self::contrasto('#14170f', $fondo)
            ? 'chiaro'
            : 'scuro';
    }

    /**
     * Rapporto di contrasto WCAG 2.1 fra due colori opachi: da 1 (identici)
     * a 21 (bianco su nero).
     */
    public static function contrasto(string $a, string $b): float
    {
        $la = self::luminanza($a);
        $lb = self::luminanza($b);

        return ($la > $lb)
            ? ($la + 0.05) / ($lb + 0.05)
            : ($lb + 0.05) / ($la + 0.05);
    }

    /** Luminanza relativa WCAG (0 = nero, 1 = bianco). */
    public static function luminanza(string $colore): float
    {
        [$r, $g, $b] = array_map(
            self::lineare(...),
            self::componenti(self::normalizzaColore($colore))
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Miscela in oklab: $quota è la parte del primo colore (0,56 = 56%),
     * come nel color-mix delle bozze.
     */
    public static function mescola(string $a, string $b, float $quota): string
    {
        $quota = max(0.0, min(1.0, $quota));
        $oa = self::aOklab(self::normalizzaColore($a));
        $ob = self::aOklab(self::normalizzaColore($b));

        return self::daOklab([
            $oa[0] * $quota + $ob[0] * (1 - $quota),
            $oa[1] * $quota + $ob[1] * (1 - $quota),
            $oa[2] * $quota + $ob[2] * (1 - $quota),
        ]);
    }

    /**
     * Riporta un colore alla forma #rrggbb minuscola. Accetta le sviste di
     * chi scrive a mano (spazi, cancelletto mancante, forma a tre cifre,
     * maiuscole); su qualunque altra cosa ripiega sul verde di serie, perché
     * un portale senza colori è meglio di un portale che non si apre.
     */
    public static function normalizzaColore(?string $colore): string
    {
        $c = strtolower(trim((string) $colore));
        $c = ltrim($c, '#');

        if (preg_match('/^[0-9a-f]{3}$/', $c)) {
            $c = $c[0].$c[0].$c[1].$c[1].$c[2].$c[2];
        }

        return preg_match('/^[0-9a-f]{6}$/', $c) ? '#'.$c : self::TINTA_PREDEFINITA;
    }

    /**
     * Spinge un colore verso il nero (o verso il bianco) finché non raggiunge
     * il contrasto richiesto sul suo riferimento. A passi piccoli, così si
     * perde il minimo indispensabile della tinta scelta dal Comune.
     *
     * Se nemmeno l'estremo basta (capita solo con riferimenti a mezza strada,
     * cioè con tinte che nessuno sceglierebbe) si restituisce l'estremo: è il
     * massimo contrasto ottenibile, e la pagina resta comunque leggibile.
     */
    private static function garantisci(string $colore, string $riferimento, float $minimo, string $verso): string
    {
        if (self::contrasto($colore, $riferimento) >= $minimo) {
            return $colore;
        }

        $estremo = $verso === 'schiarisci' ? '#ffffff' : '#000000';

        for ($passo = 1; $passo <= 40; $passo++) {
            $tentativo = self::mescola($colore, $estremo, 1 - $passo * 0.025);
            if (self::contrasto($tentativo, $riferimento) >= $minimo) {
                return $tentativo;
            }
        }

        return $estremo;
    }

    /** @return array{float,float,float} le tre componenti sRGB in 0..1 */
    private static function componenti(string $hex): array
    {
        return [
            hexdec(substr($hex, 1, 2)) / 255,
            hexdec(substr($hex, 3, 2)) / 255,
            hexdec(substr($hex, 5, 2)) / 255,
        ];
    }

    /** Da sRGB con gamma a sRGB lineare. */
    private static function lineare(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    /** Da sRGB lineare a sRGB con gamma. */
    private static function gamma(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055;
    }

    /**
     * sRGB -> lineare -> LMS -> oklab (matrici di Björn Ottosson, le stesse
     * che usa color-mix(in oklab, ...) nei browser).
     *
     * @return array{float,float,float}
     */
    private static function aOklab(string $hex): array
    {
        [$r, $g, $b] = array_map(self::lineare(...), self::componenti($hex));

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        // La radice cubica è il passaggio che rende lo spazio percettivo:
        // senza, le miscele scivolerebbero verso il grigio come in sRGB.
        $l = self::radiceCubica($l);
        $m = self::radiceCubica($m);
        $s = self::radiceCubica($s);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /**
     * La strada inversa. Le componenti fuori dal gamut sRGB (possibili con
     * tinte molto sature) vengono semplicemente tagliate a 0..255: è quello
     * che fa anche il browser quando stampa il risultato di un color-mix.
     *
     * @param  array{float,float,float}  $oklab
     */
    private static function daOklab(array $oklab): string
    {
        [$L, $a, $b] = $oklab;

        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $canali = [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];

        $hex = '#';
        foreach ($canali as $canale) {
            $valore = (int) round(max(0.0, min(1.0, self::gamma($canale))) * 255);
            $hex .= str_pad(dechex($valore), 2, '0', STR_PAD_LEFT);
        }

        return $hex;
    }

    /** Radice cubica che regge anche i valori negativi (** non lo fa). */
    private static function radiceCubica(float $v): float
    {
        return $v < 0 ? -((-$v) ** (1 / 3)) : $v ** (1 / 3);
    }
}
