<?php

namespace App\Services\Export;

/**
 * Un foglio di calcolo .xlsx vero, scritto senza librerie.
 *
 * Perche' non il CSV. Il CSV che uscivamo prima Excel lo apre, ma non e' un
 * foglio: niente intestazione in grassetto, niente colonne larghe il giusto,
 * niente filtri, e i numeri con la virgola diventano testo o date a seconda
 * di come e' configurato il computer di chi apre. Un .xlsx dice a Excel che
 * cosa e' testo, che cosa e' numero e che cosa e' data, e non si discute.
 *
 * Perche' non una libreria. Un .xlsx e' una cartella zip di file XML: per
 * scrivere un elenco con una riga di intestazione bastano poche centinaia di
 * righe, e in cambio non si aggiunge al programma una dipendenza grossa da
 * aggiornare per anni. Le parti fisse (tipi, relazioni, stili) sono qui
 * sotto per intero, cosi' si vede tutto quello che finisce nel file.
 *
 * Memoria: le righe si scrivono man mano su un file temporaneo, non si
 * accumulano. Un censimento da centomila elementi non deve stare in RAM.
 */
class FoglioXlsx
{
    /** Colonne dichiarate: titolo, larghezza e tipo. */
    private array $colonne = [];

    private int $righe = 0;

    /** @var resource|null */
    private $foglio = null;

    private ?string $percorsoFoglio = null;

    public function __construct(private string $nomeFoglio = 'Foglio1') {}

    /**
     * Le colonne del foglio.
     *
     * @param  list<array{titolo: string, larghezza?: int, tipo?: 'testo'|'numero'|'data'}>  $colonne
     */
    public function intestazione(array $colonne): static
    {
        $this->colonne = $colonne;
        $this->apri();

        $celle = '';
        foreach ($colonne as $i => $colonna) {
            $celle .= $this->cellaTesto($this->riferimento($i, 1), $colonna['titolo'], 1);
        }
        fwrite($this->foglio, '<row r="1">'.$celle.'</row>');
        $this->righe = 1;

        return $this;
    }

    /**
     * Una riga di valori, nell'ordine delle colonne.
     *
     * I valori arrivano grezzi: le date come oggetti o stringhe Y-m-d, i
     * numeri come numeri. La conversione la fa il foglio, secondo il tipo
     * dichiarato nella colonna: e' l'unico modo perche' Excel li ordini e li
     * sommi invece di trattarli come testo.
     *
     * @param  list<mixed>  $valori
     */
    public function riga(array $valori): static
    {
        $this->apri();
        $numero = ++$this->righe;
        $celle = '';

        foreach ($this->colonne as $i => $colonna) {
            $valore = $valori[$i] ?? null;
            if ($valore === null || $valore === '') {
                continue; // cella vuota: non si scrive, il file resta piu' piccolo
            }

            $riferimento = $this->riferimento($i, $numero);
            $celle .= match ($colonna['tipo'] ?? 'testo') {
                'numero' => is_numeric($valore)
                    ? '<c r="'.$riferimento.'"><v>'.rtrim(rtrim(number_format((float) $valore, 4, '.', ''), '0'), '.').'</v></c>'
                    // Un "numero" che non lo e' (un trattino, una nota) non si
                    // butta: si scrive come testo, e chi legge lo vede
                    : $this->cellaTesto($riferimento, (string) $valore),
                'data' => ($seriale = $this->seriale($valore)) !== null
                    ? '<c r="'.$riferimento.'" s="2"><v>'.$seriale.'</v></c>'
                    : $this->cellaTesto($riferimento, (string) $valore),
                default => $this->cellaTesto($riferimento, (string) $valore),
            };
        }

        fwrite($this->foglio, '<row r="'.$numero.'">'.$celle.'</row>');

        return $this;
    }

    /**
     * Chiude il foglio e restituisce il percorso del file .xlsx creato.
     * Chi lo riceve deve consegnarlo e poi cancellarlo.
     */
    public function scrivi(): string
    {
        $this->apri();
        fwrite($this->foglio, '</sheetData>');
        // Il filtro automatico sull'intestazione: Excel lo mostra come le
        // frecce in cima alle colonne, ed e' meta' del motivo per cui si
        // chiede un foglio vero invece di un CSV
        if ($this->colonne !== []) {
            fwrite($this->foglio, '<autoFilter ref="A1:'.$this->colonnaLettera(count($this->colonne) - 1).max(1, $this->righe).'"/>');
        }
        fwrite($this->foglio, '</worksheet>');
        fclose($this->foglio);
        $this->foglio = null;

        $percorso = tempnam(sys_get_temp_dir(), 'xlsx_').'.xlsx';
        $zip = new \ZipArchive;
        if ($zip->open($percorso, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Non riesco a creare il foglio di calcolo.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFile($this->percorsoFoglio, 'xl/worksheets/sheet1.xml');
        $zip->close();

        @unlink($this->percorsoFoglio);
        $this->percorsoFoglio = null;

        return $percorso;
    }

    /** Il tipo MIME dei fogli di calcolo Excel. */
    public static function mime(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    private function apri(): void
    {
        if ($this->foglio !== null) {
            return;
        }

        $this->percorsoFoglio = tempnam(sys_get_temp_dir(), 'sheet_');
        $this->foglio = fopen($this->percorsoFoglio, 'w');

        $cols = '';
        foreach ($this->colonne as $i => $colonna) {
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.($colonna['larghezza'] ?? 18).'" customWidth="1"/>';
        }

        fwrite($this->foglio,
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // Intestazione bloccata: scorrendo mille righe si continua a
            // sapere che cosa sono le colonne
            .'<sheetViews><sheetView workbookViewId="0">'
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>');
    }

    private function cellaTesto(string $riferimento, string $valore, int $stile = 0): string
    {
        return '<c r="'.$riferimento.'"'.($stile ? ' s="'.$stile.'"' : '').' t="inlineStr">'
            .'<is><t xml:space="preserve">'.$this->xml($valore).'</t></is></c>';
    }

    /** Testo pronto per l'XML, senza i caratteri di controllo che lo romperebbero. */
    private function xml(string $valore): string
    {
        $pulito = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $valore) ?? $valore;

        return htmlspecialchars($pulito, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Il numero con cui Excel rappresenta una data: giorni dal 30/12/1899.
     * Null quando il valore non e' una data leggibile: meglio scriverla come
     * testo che inventare un giorno.
     */
    private function seriale(mixed $valore): ?int
    {
        try {
            $data = $valore instanceof \DateTimeInterface
                ? \Carbon\CarbonImmutable::instance($valore)
                : \Carbon\CarbonImmutable::parse((string) $valore);
        } catch (\Throwable) {
            return null;
        }

        // Dal 30/12/1899 in avanti: l'ordine conta, invertito darebbe giorni
        // negativi e Excel mostrerebbe celle di errore
        return (int) \Carbon\CarbonImmutable::create(1899, 12, 30)->diffInDays($data->startOfDay());
    }

    private function riferimento(int $colonna, int $riga): string
    {
        return $this->colonnaLettera($colonna).$riga;
    }

    /** 0 -> A, 25 -> Z, 26 -> AA. */
    private function colonnaLettera(int $indice): string
    {
        $lettere = '';
        for ($i = $indice; $i >= 0; $i = intdiv($i, 26) - 1) {
            $lettere = chr(65 + $i % 26).$lettere;
        }

        return $lettere;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        // Il nome del foglio in Excel non ammette : \ / ? * [ ] e sta in 31
        // caratteri: si taglia qui, o il file non si apre
        $nome = mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $this->nomeFoglio) ?: 'Foglio1', 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->xml($nome).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /**
     * Gli stili: 0 normale, 1 intestazione in grassetto, 2 data italiana.
     * L'ordine conta, e' quello a cui rimandano gli attributi s="..." delle
     * celle.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="DD/MM/YYYY"/></numFmts>'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            // Lo stile "Normale": senza, i lettori piu' pignoli avvisano che
            // il foglio non ha uno stile predefinito
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
