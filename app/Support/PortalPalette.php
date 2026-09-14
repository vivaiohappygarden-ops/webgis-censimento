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
     * centesimi, colore di base]. "56%" significa 56 parti di sorgente e 44
     * di base, mescolate in oklab, esattamente come
     * color-mix(in oklab, var(--tinta) 56%, #050d09).
     *
     * La sorgente è sempre la tinta, tranne l'ambra scura che nasce
     * dall'ambra: così resta ambra anche quando la tinta è un rosso mattone.
     *
     * I token delle illustrazioni (fogliame, luce, corteccia, la carta
     * disegnata) sono stati tolti il 14/09/2026 insieme ai disegni: la veste
     * istituzionale non ha figure, e una tavolozza che descrive un disegno
     * che non esiste piu' fa perdere tempo a chi la legge.
     */
    private const RICETTE = [
        // --- notte e bosco: i fondi scuri di testata, copertina e piede ----
        'notte' => ['tinta', 56, '#050d09'],
        'notte-fondo' => ['tinta', 32, '#030806'],
        'bosco' => ['tinta', 88, '#0b1a12'],

        // --- l'ambra del "sei qui" sulla carta -----------------------------
        //     Unico avanzo della veste editoriale del 29/08, e per un motivo:
        //     l'anello che segna l'elemento aperto sulla mappa deve staccarsi
        //     dai quattro colori di stato e dal colore dell'ente, qualunque
        //     tinta abbia scelto il Comune. Non e' decorazione, e' un segno.
        'oro' => ['tinta', 12, '#c2933a'],
        'oro-scuro' => ['oro', 46, '#2e2207'],
        'oro-chiaro' => ['tinta', 8, '#eed7a2'],

        // --- il corpo chiaro delle pagine ---------------------------------
        //     Grigi neutri, non avorio: il registro e' quello di un atto
        //     dell'ente (decisione committente 14/09/2026). La tinta del
        //     Comune entra in dose piccolissima, quel tanto che basta perche'
        //     la pagina non sia la stessa in tutti i Comuni.
        'avorio' => ['tinta', 3, '#f4f6f5'],
        'avorio-2' => ['tinta', 5, '#e9edec'],
        'carta' => ['tinta', 1, '#ffffff'],
        'chiaro' => ['tinta', 6, '#f2f5f4'],
        'chiaro-2' => ['tinta', 22, '#d3d9d7'],
        'inchiostro' => ['tinta', 55, '#14181a'],
        'inchiostro-2' => ['tinta', 40, '#5b6360'],
        'filo' => ['tinta', 10, '#e2e7e5'],
        'filo-2' => ['tinta', 28, '#a9b2af'],

        // --- il verde delle aree sulla carta -------------------------------
        'm-parco' => ['tinta', 22, '#dbe3c2'],
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
        // di misura normale e fa da fondo al pulsante principale, che porta
        // scritte in chiaro: si misura sul chiaro, che è il più severo dei due
        // fondi (più scuro dell'avorio), e vale per entrambi i versi.
        ['bosco', 'scurisci', 'chiaro', 4.5],
        // Gli occhielli in oro sono maiuscoletto piccolo: testo normale.
        ['oro-scuro', 'scurisci', 'avorio', 4.5],
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
