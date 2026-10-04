<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\LettorePdf;
use Tests\TestCase;

/**
 * La stampa della mappa in PDF: l'immagine disegnata dal browser entra in un
 * foglio con intestazione, titolo, data, scala a video e grafica, coordinate
 * del centro, legenda e attribuzione; il foglio segue le proporzioni
 * dell'immagine; un'immagine illeggibile si rifiuta; l'esportazione resta nel
 * registro e fra i documenti.
 */
class MappaPdfTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->actingAsTenantUser($this->utente);
    }

    /** Un'immagine di prova come la manderebbe il browser (data URI). */
    private function immagine(int $larghezza, int $altezza, string $formato = 'jpeg'): string
    {
        $img = imagecreatetruecolor($larghezza, $altezza);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 220, 235, 220));
        imagefilledellipse($img, (int) ($larghezza / 2), (int) ($altezza / 2), 80, 80, (int) imagecolorallocate($img, 22, 163, 74));
        ob_start();
        $formato === 'png' ? imagepng($img) : imagejpeg($img, null, 85);
        $byte = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/'.$formato.';base64,'.base64_encode($byte);
    }

    private function richiesta(array $extra = []): array
    {
        return array_replace([
            'immagine' => $this->immagine(800, 500),
            'titolo' => 'Mappa del verde',
            'sottotitolo' => 'Comune di Prova - Parco della Pace',
            'scala' => '1:2.500',
            'metri_larghezza' => 400,
            'rotazione' => 0,
            'centro' => ['wgs84' => '45,46520 N 9,19050 E', 'metrico' => 'E 1.514.123 N 5.034.567', 'sistema' => 'EPSG:7791'],
            'legenda' => [
                ['etichetta' => 'Vegetazione', 'colore' => '#16a34a', 'forma' => 'punto'],
                ['etichetta' => 'Siepi, filari e cigli (vegetazione lineare)', 'colore' => '#15803d', 'forma' => 'linea'],
                ['etichetta' => 'Percorsi e piste (tratteggio)', 'colore' => '#6b7280', 'forma' => 'tratteggio'],
                ['etichetta' => 'Aree di gestione', 'colore' => '#15803d', 'forma' => 'area'],
                ['etichetta' => 'Elementi con lavori aperti', 'colore' => '#ea580c', 'forma' => 'anello'],
                ['etichetta' => 'Segnalazioni aperte', 'colore' => '#dc2626', 'forma' => 'segnalazione'],
            ],
            'attribuzione' => '© OpenStreetMap contributors',
        ], $extra);
    }

    public function test_la_mappa_esce_in_pdf_con_intestazione_scala_legenda_e_attribuzione(): void
    {
        Organization::query()->whereKey($this->organizzazione->id)->update(['branding' => json_encode(['pec' => 'studio@pec.it'])]);

        $risposta = $this->postJson('/api/v1/exports/mappa.pdf', $this->richiesta())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $risposta->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/MediaBox [0 0 841.89 595.28]', $pdf, "l'immagine larga va su un foglio orizzontale");
        $this->assertStringContainsString('/DCTDecode', $pdf, "l'immagine e' incorporata come JPEG");
        $testo = LettorePdf::testo($pdf);
        foreach (['Mappa del verde', 'Comune di Prova - Parco della Pace', '1:2.500', '45,46520 N 9,19050 E', 'EPSG:7791',
            'Legenda', 'Vegetazione', 'Siepi, filari e cigli', 'Percorsi e piste', 'Aree di gestione', 'Elementi con lavori aperti', 'Segnalazioni aperte',
            'OpenStreetMap', '100 m', 'Pagina 1 di 1', $this->organizzazione->name, 'studio@pec.it'] as $atteso) {
            $this->assertStringContainsString($atteso, $testo, "nel PDF manca: {$atteso}");
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'export.mappa_pdf', 'tenant_id' => $this->organizzazione->id]);
        $righe = collect($this->getJson('/api/v1/documenti?tipo=esportazione')->assertOk()->json('data'));
        $riga = $righe->first(fn ($r) => str_contains($r['titolo'] ?? '', 'Mappa del verde (PDF)'));
        $this->assertNotNull($riga, 'la stampa della mappa compare fra le esportazioni in Documenti');
        $this->assertSame('/mappa', $riga['href'] ?? null);
    }

    public function test_un_immagine_alta_va_su_un_foglio_verticale_e_il_png_si_converte(): void
    {
        $pdf = $this->postJson('/api/v1/exports/mappa.pdf', $this->richiesta(['immagine' => $this->immagine(500, 800, 'png'), 'legenda' => [], 'metri_larghezza' => null, 'scala' => null]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('/MediaBox [0 0 595.28 841.89]', $pdf);
        $this->assertStringContainsString('/DCTDecode', $pdf);
        $testo = LettorePdf::testo($pdf);
        $this->assertStringContainsString('Mappa del verde', $testo);
        $this->assertStringNotContainsString('Legenda', $testo, 'senza voci la legenda non si stampa');
        $this->assertStringNotContainsString('scala a video', $testo);
    }

    public function test_un_immagine_illeggibile_o_una_legenda_sbagliata_si_rifiutano(): void
    {
        $this->postJson('/api/v1/exports/mappa.pdf', $this->richiesta(['immagine' => 'data:image/jpeg;base64,'.base64_encode('non sono un JPEG')]))
            ->assertStatus(422)->assertJsonValidationErrors(['immagine']);
        $this->postJson('/api/v1/exports/mappa.pdf', $this->richiesta(['immagine' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['immagine']);
        $this->postJson('/api/v1/exports/mappa.pdf', $this->richiesta(['legenda' => [['etichetta' => 'X', 'colore' => 'verde', 'forma' => 'punto']]]))
            ->assertStatus(422)->assertJsonValidationErrors(['legenda.0.colore']);
        $this->postJson('/api/v1/exports/mappa.pdf', $this->richiesta(['legenda' => [['etichetta' => 'X', 'colore' => '#16a34a', 'forma' => 'stella']]]))
            ->assertStatus(422)->assertJsonValidationErrors(['legenda.0.forma']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'export.mappa_pdf']);
    }
}
