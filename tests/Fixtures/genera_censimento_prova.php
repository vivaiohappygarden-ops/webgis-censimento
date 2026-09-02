<?php

/**
 * Genera un .xlsx minimo ma conforme (OOXML SpreadsheetML) senza librerie:
 * serve come fixture piccola e leggibile da ogr2ogr. Due fogli: "Rilievo"
 * con i dati e "Note" per provare la regola del primo foglio.
 */
function cella(string $rif, $valore): string
{
    if ($valore === null) {
        return '';
    }
    if (is_int($valore) || is_float($valore)) {
        return "<c r=\"{$rif}\"><v>{$valore}</v></c>";
    }
    $testo = htmlspecialchars((string) $valore, ENT_XML1);

    return "<c r=\"{$rif}\" t=\"inlineStr\"><is><t>{$testo}</t></is></c>";
}

function foglio(array $righe): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($righe as $r => $riga) {
        $xml .= '<row r="'.($r + 1).'">';
        foreach ($riga as $c => $valore) {
            $rif = chr(65 + $c).($r + 1);
            $xml .= cella($rif, $valore);
        }
        $xml .= '</row>';
    }

    return $xml.'</sheetData></worksheet>';
}

$rilievo = [
    ['ETICHETTA', 'SPECIE', 'LONGITUDINE', 'LATITUDINE', 'ALTEZZA'],
    ['T-101', 'Tilia cordata', 9.19, 45.464, 12.5],
    ['T-102', 'Acer campestre', '9,191', '45,465', '8,5'],
    ['T-103', 'Quercus robur', null, null, '10'],
    ['T-104', 'Platanus x', 'boh', 45.466, '11'],
];
$note = [['SOLO', 'NOTE'], ['a', 'b']];

$zip = new ZipArchive;
$dest = $argv[1] ?? __DIR__.'/prova.xlsx';
@unlink($dest);
$zip->open($dest, ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    .'<Default Extension="xml" ContentType="application/xml"/>'
    .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    .'</Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    .'</Relationships>');
$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    .'<sheets><sheet name="Rilievo" sheetId="1" r:id="rId1"/><sheet name="Note" sheetId="2" r:id="rId2"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
    .'</Relationships>');
$zip->addFromString('xl/worksheets/sheet1.xml', foglio($rilievo));
$zip->addFromString('xl/worksheets/sheet2.xml', foglio($note));
$zip->close();
echo "scritto {$dest}\n";
