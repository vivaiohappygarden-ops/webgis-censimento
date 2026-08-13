<?php

namespace App\Services\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Stampe PDF dalle viste Blade, senza risorse esterne. */
class PdfRenderer
{
    public function render(string $view, array $data): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans Mono');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data)->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
