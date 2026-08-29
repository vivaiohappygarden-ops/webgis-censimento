<?php

namespace Tests\Unit;

use App\Support\PortalPalette;
use PHPUnit\Framework\TestCase;

/**
 * La tavolozza del portale pubblico nasce dal solo colore scelto dal Comune.
 * Qui si controlla che nasca sempre: completa, con colori validi, con i chiari
 * chiari e gli scuri scuri, e soprattutto leggibile con qualunque tinta —
 * perché la tinta la sceglie il committente, il contrasto no.
 */
class PortalPaletteTest extends TestCase
{
    /** Le quattro tinte con cui si collauda: verde, blu, rosso mattone, viola. */
    private const TINTE = ['#14532d', '#0b4a6f', '#7c2d12', '#4c1d95'];

    /** Ogni token dichiarato deve uscire dalla tavolozza. */
    public function test_la_tavolozza_esce_completa(): void
    {
        $token = PortalPalette::da('#14532d')->token();

        $attesi = [
            'notte', 'notte-fondo', 'bosco',
            'fogliame-lontano', 'fogliame-medio', 'fogliame-vicino',
            'luce', 'luce-2',
            'oro', 'oro-scuro', 'oro-chiaro',
            'avorio', 'avorio-2', 'carta', 'chiaro', 'chiaro-2',
            'inchiostro', 'inchiostro-2', 'filo', 'filo-2',
            'corteccia', 'corteccia-2',
        ];

        foreach ($attesi as $nome) {
            $this->assertArrayHasKey($nome, $token, "Manca il token {$nome}");
        }
    }

    public function test_ogni_colore_e_un_esadecimale_valido(): void
    {
        foreach (self::TINTE as $tinta) {
            foreach (PortalPalette::da($tinta)->token() as $nome => $valore) {
                $this->assertMatchesRegularExpression(
                    '/^#[0-9a-f]{6}$/',
                    $valore,
                    "Il token {$nome} della tinta {$tinta} non è un esadecimale valido: {$valore}"
                );
            }
        }
    }

    /**
     * I chiari devono restare chiari e gli scuri scuri anche con una tinta
     * sbagliata: se si invertissero, una pagina si stamperebbe bianca su
     * bianco senza che nessuno se ne accorga prima del collaudo.
     */
    public function test_i_chiari_restano_chiari_e_gli_scuri_scuri(): void
    {
        $tinte = array_merge(self::TINTE, ['#ffffff', '#000000', '#fef08a']);

        foreach ($tinte as $tinta) {
            $token = PortalPalette::da($tinta)->token();

            foreach (['carta', 'avorio', 'avorio-2', 'chiaro', 'oro-chiaro', 'luce'] as $nome) {
                $this->assertGreaterThan(
                    0.5,
                    PortalPalette::luminanza($token[$nome]),
                    "Il token chiaro {$nome} è diventato scuro con la tinta {$tinta}"
                );
            }

            foreach (['notte', 'notte-fondo', 'bosco', 'inchiostro', 'oro-scuro', 'corteccia-2'] as $nome) {
                $this->assertLessThan(
                    0.25,
                    PortalPalette::luminanza($token[$nome]),
                    "Il token scuro {$nome} è diventato chiaro con la tinta {$tinta}"
                );
            }

            // Il fondo del piede non può essere più chiaro della testata
            $this->assertLessThanOrEqual(
                PortalPalette::luminanza($token['notte']) + 0.01,
                PortalPalette::luminanza($token['notte-fondo']),
                "Con la tinta {$tinta} la notte di fondo è più chiara della notte"
            );
        }
    }

    /**
     * Prospettiva aerea: il fogliame lontano è più chiaro di quello di mezzo,
     * che è più chiaro di quello vicino. È il disegno stesso della copertina.
     */
    public function test_i_piani_del_fogliame_restano_in_ordine(): void
    {
        foreach (self::TINTE as $tinta) {
            $token = PortalPalette::da($tinta)->token();

            $lontano = PortalPalette::luminanza($token['fogliame-lontano']);
            $medio = PortalPalette::luminanza($token['fogliame-medio']);
            $vicino = PortalPalette::luminanza($token['fogliame-vicino']);

            $this->assertGreaterThan($medio, $lontano, "Piani invertiti con la tinta {$tinta}");
            $this->assertGreaterThan($vicino, $medio, "Piani invertiti con la tinta {$tinta}");
        }
    }

    /**
     * Il contratto vero: con qualunque tinta il testo si legge. Si controllano
     * le coppie che portano davvero delle parole, sul chiaro e sullo scuro.
     */
    public function test_il_testo_si_legge_con_ogni_tinta(): void
    {
        // Anche le tinte che nessuno sceglierebbe: se un tecnico incolla un
        // colore chiarissimo, il portale deve restare leggibile lo stesso.
        $tinte = array_merge(self::TINTE, ['#ffffff', '#000000', '#fef08a', '#aabbcc']);

        $coppie = [
            ['inchiostro', 'avorio', 'testo corrente sul fondo di pagina'],
            ['inchiostro', 'carta', 'testo corrente sulla scheda'],
            ['inchiostro-2', 'avorio', 'testo secondario sul fondo di pagina'],
            ['bosco', 'avorio', 'titoli e collegamenti'],
            ['oro-scuro', 'avorio', 'occhielli in oro'],
            ['chiaro', 'notte', 'testo sulla testata scura'],
            ['chiaro', 'notte-fondo', 'testo sul piede scuro'],
            ['oro-chiaro', 'notte', 'occhielli sulla testata scura'],
            ['chiaro', 'bosco', 'scritta del pulsante principale'],
        ];

        foreach ($tinte as $tinta) {
            $token = PortalPalette::da($tinta)->token();

            foreach ($coppie as [$testo, $fondo, $dove]) {
                $rapporto = PortalPalette::contrasto($token[$testo], $token[$fondo]);
                $this->assertGreaterThanOrEqual(
                    4.5,
                    round($rapporto, 2),
                    "Con la tinta {$tinta} il contrasto {$testo} su {$fondo} ({$dove}) è solo ".round($rapporto, 2).':1'
                );
            }

            // Il bordo dei campi da compilare è un componente d'interfaccia:
            // gli bastano 3:1, ma quelli deve averli.
            $this->assertGreaterThanOrEqual(
                3.0,
                round(PortalPalette::contrasto($token['filo-2'], $token['avorio']), 2),
                "Con la tinta {$tinta} il filo forte non si vede sul fondo di pagina"
            );
        }
    }

    /**
     * I quattro colori di stato non passano dalla tavolozza (non si ritingono
     * mai), ma il portale li stampa sopra --carta: quel contrasto va tenuto
     * d'occhio qui, perché è l'unico posto che lo verifica.
     */
    public function test_i_colori_di_stato_si_leggono_sulla_carta(): void
    {
        foreach (self::TINTE as $tinta) {
            $carta = PortalPalette::da($tinta)->colore('carta');

            foreach (['#15803d', '#0369a1', '#b45309', '#b91c1c'] as $stato) {
                $this->assertGreaterThanOrEqual(
                    4.5,
                    round(PortalPalette::contrasto($stato, $carta), 2),
                    "Lo stato {$stato} non si legge sulla carta della tinta {$tinta}"
                );
                // Sul colore pieno della targhetta il testo va in bianco
                $this->assertSame('chiaro', PortalPalette::testoSopra($stato));
            }
        }
    }

    public function test_dice_se_il_testo_sopra_va_chiaro_o_scuro(): void
    {
        $this->assertSame('chiaro', PortalPalette::testoSopra('#14532d'));
        $this->assertSame('scuro', PortalPalette::testoSopra('#faf5ea'));
        $this->assertSame('scuro', PortalPalette::testoSopra('#eed7a2'));
        $this->assertSame('chiaro', PortalPalette::testoSopra('#000000'));
    }

    /**
     * Il colore lo scrive un umano nel gestionale: le sviste non devono far
     * saltare il portale, devono ripiegare sul verde di serie.
     */
    public function test_un_colore_sporco_non_fa_esplodere_niente(): void
    {
        $sporchi = [null, '', '   ', 'verde', '#12345', '#gg0011', 'rgb(1,2,3)', '#', '####'];

        foreach ($sporchi as $sporco) {
            $tavolozza = PortalPalette::da($sporco);

            $this->assertSame(
                PortalPalette::TINTA_PREDEFINITA,
                $tavolozza->tinta,
                'Il colore '.var_export($sporco, true).' doveva ripiegare sul verde di serie'
            );
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $tavolozza->colore('avorio'));
        }
    }

    /** Le forme accettate: senza cancelletto, a tre cifre, con le maiuscole. */
    public function test_accetta_le_forme_scritte_a_mano(): void
    {
        $this->assertSame('#14532d', PortalPalette::normalizzaColore('14532D'));
        $this->assertSame('#14532d', PortalPalette::normalizzaColore('  #14532D  '));
        $this->assertSame('#aabbcc', PortalPalette::normalizzaColore('#abc'));
        $this->assertSame('#ffffff', PortalPalette::normalizzaColore('FFF'));

        // Stessa tinta scritta in due modi, stessa tavolozza
        $this->assertSame(
            PortalPalette::da('#14532d')->token(),
            PortalPalette::da('14532D')->token()
        );
    }

    /** Le righe pronte per il blocco :root del layout. */
    public function test_stampa_le_righe_delle_variabili_css(): void
    {
        $righe = PortalPalette::da('#14532d')->righeCss('    ');

        $this->assertStringContainsString('    --notte: #', $righe);
        $this->assertStringContainsString('    --inchiostro: #', $righe);
        $this->assertStringContainsString('    --corteccia-2: #3b3126;', $righe);

        // Una riga per token, nella forma "--nome: #rrggbb;" e nient'altro
        foreach (explode("\n", $righe) as $riga) {
            $this->assertMatchesRegularExpression('/^ {4}--[a-z0-9-]+: #[0-9a-f]{6};$/', $riga);
        }
    }

    /**
     * La miscela avviene in oklab: agli estremi deve restituire esattamente i
     * due colori di partenza, e a metà strada un colore che sta in mezzo.
     */
    public function test_la_miscela_in_oklab_regge_agli_estremi(): void
    {
        $this->assertSame('#14532d', PortalPalette::mescola('#14532d', '#ffffff', 1.0));
        $this->assertSame('#ffffff', PortalPalette::mescola('#14532d', '#ffffff', 0.0));

        // A metà strada fra nero e bianco esce un grigio medio all'occhio, non
        // alla fotometria: in oklab la mezzeria sta intorno al 12% di
        // luminanza relativa, ed è proprio quello che rende naturali le
        // miscele con i verdi scuri.
        $meta = PortalPalette::mescola('#000000', '#ffffff', 0.5);
        $luminanza = PortalPalette::luminanza($meta);
        $this->assertGreaterThan(0.08, $luminanza);
        $this->assertLessThan(0.25, $luminanza);
        $this->assertSame('#636363', $meta);
    }

    /** Il rapporto di contrasto WCAG, sui due casi che si conoscono a memoria. */
    public function test_il_rapporto_di_contrasto_e_quello_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, PortalPalette::contrasto('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, PortalPalette::contrasto('#14532d', '#14532d'), 0.01);
    }

    /** Un token che non esiste è un errore di programmazione, non un colore vuoto. */
    public function test_un_token_inventato_si_ferma_subito(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PortalPalette::da('#14532d')->colore('turchese');
    }
}
