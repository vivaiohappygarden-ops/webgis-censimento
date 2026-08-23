<?php

namespace Tests\Support;

use App\Services\Pdf\PdfRenderer;

/**
 * Sostituisce il generatore di PDF nei test.
 *
 * Tiene da parte i dati passati alla vista e compone davvero il modello Blade
 * in HTML: così un controllo può leggere quello che finisce nel documento
 * senza dover riaprire un PDF, e si accorge sia se il dato non arriva sia se
 * il modello smette di stamparlo.
 */
class RaccoglitorePdf extends PdfRenderer
{
    /** @var array<string, array<string, mixed>> dati per nome della vista */
    public array $dati = [];

    /** @var array<string, string> HTML composto per nome della vista */
    public array $html = [];

    public function render(string $view, array $data, string $paper = 'A4'): string
    {
        $this->dati[$view] = $data;
        $this->html[$view] = view($view, $data)->render();

        return '%PDF-1.4 (finto, per i test)';
    }
}
