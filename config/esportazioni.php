<?php

return [
    // Righe al massimo nell'elenco degli elementi in PDF (oltre, il documento
    // dichiara di riportare le prime e rimanda a Excel). Ventimila righe sono
    // circa 450 pagine e qualche secondo: il tetto protegge il server, non
    // l'uso normale.
    'pdf_righe_massime' => (int) env('ESPORTAZIONE_PDF_RIGHE', 20000),
];
