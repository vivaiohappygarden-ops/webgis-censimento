<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Pdf\CarattereTrueType;
use App\Services\Pdf\ScrittorePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\LettorePdf;
use Tests\TestCase;

/**
 * L'elenco filtrato in PDF: stesse colonne (quelle da foglio) e stessi filtri
 * di CSV ed Excel, intestazione dell'organizzazione, tetto di righe
 * dichiarato, esportazione annotata fra i documenti. Il PDF e' scritto a mano
 * (ScrittorePdf) e si rilegge con LettorePdf.
 */
class EsportaPdfElencoTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->actingAsTenantUser($this->utente);
    }

    private function albero(string $codice, array $scheda = []): string
    {
        $tipo = \App\Models\CatalogObjectType::query()->where('code', 'P103108')->first() ?? $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $id = $this->postJson('/api/v1/assets', ['area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'census_code' => $codice, 'geometry' => $this->pointGeometry()])
            ->assertCreated()->json('data.id');
        if ($scheda) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $scheda])->assertOk();
        }

        return $id;
    }

    public function test_il_pdf_esporta_l_elenco_filtrato_con_intestazione_e_colonne_da_foglio(): void
    {
        Organization::query()->whereKey($this->organizzazione->id)->update(['branding' => json_encode(['pec' => 'studio@pec.it'])]);
        $this->albero('ALB-0001', ['species' => 'Tilia cordata', 'height_m' => 12.5, 'dbh_cm' => 38]);
        $this->albero('ALB-0002', ['species' => 'Quercus ilex']);
        $this->albero('ALB-0003');

        $risposta = $this->get('/api/v1/exports/assets.pdf?q=tilia')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $risposta->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertStringContainsString('/MediaBox [0 0 841.89 595.28]', $pdf, 'A4 orizzontale');
        $this->assertStringContainsString('/BaseFont /DejaVuSansMono ', $pdf, 'lo stesso carattere delle altre stampe, incorporato');
        $testo = LettorePdf::testo($pdf);

        // Stessi filtri dell'elenco: una riga sola, con i valori formattati all'italiana
        $this->assertStringContainsString('ALB-0001', $testo);
        $this->assertStringNotContainsString('ALB-0002', $testo);
        $this->assertStringContainsString('Tilia cordata', $testo);
        $this->assertStringContainsString(' 12,5 ', $testo);
        $this->assertStringContainsString(' 38,0', $testo);
        $this->assertStringContainsString('Filtri: ricerca "tilia" - 1 elemento - stampato il', $testo);
        $this->assertStringContainsString('Pagina 1 di 1', $testo);
        // Le colonne sono quelle segnate per il foglio: codice, specie e misure si', note e categoria no
        $this->assertStringContainsString('Codice Tipo Descrizione tipo Committente Area', $testo);
        $this->assertStringContainsString('Specie', $testo);
        $this->assertStringNotContainsString('Categoria', $testo);
        $this->assertStringNotContainsString('Nome comune', $testo);
        $this->assertStringNotContainsString('Perimetro', $testo);
        // In cima l'intestazione dell'organizzazione
        $this->assertStringContainsString($this->organizzazione->name, $testo);
        $this->assertStringContainsString('PEC studio@pec.it', $testo);
        $this->assertStringNotContainsString('riporta i primi', $testo);

        // Senza filtri: tutti, nell'ordine dei cartellini, e il registro delle esportazioni lo conosce
        $testo = LettorePdf::testo($this->get('/api/v1/exports/assets.pdf')->assertOk()->getContent());
        $this->assertStringContainsString('Tutti gli elementi - 3 elementi', $testo);
        $posizioni = array_map(fn ($c) => strpos($testo, $c), ['ALB-0001', 'ALB-0002', 'ALB-0003']);
        $this->assertNotContains(false, $posizioni);
        $this->assertSame($posizioni, array_values(collect($posizioni)->sort()->all()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'export.assets_pdf', 'user_id' => $this->utente->id]);
        $righe = collect($this->getJson('/api/v1/documenti?tipo=esportazione')->assertOk()->json('data'));
        $this->assertSame(2, $righe->where('titolo', 'Elenco del censimento (PDF)')->count());
    }

    public function test_oltre_il_tetto_di_righe_il_pdf_lo_dice_e_un_cliente_non_esporta(): void
    {
        config(['esportazioni.pdf_righe_massime' => 2]);
        foreach (['ALB-0001', 'ALB-0002', 'ALB-0003'] as $codice) {
            $this->albero($codice);
        }
        $testo = LettorePdf::testo($this->get('/api/v1/exports/assets.pdf')->assertOk()->getContent());
        $this->assertStringContainsString('Il PDF riporta i primi 2 elementi su 3, in ordine di codice', $testo);
        $this->assertStringContainsString('ALB-0002', $testo);
        $this->assertStringNotContainsString('ALB-0003', $testo);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export.assets_pdf']);

        // Senza elementi il documento lo scrive, non resta una tabella vuota
        $testo = LettorePdf::testo($this->get('/api/v1/exports/assets.pdf?q=inesistente')->assertOk()->getContent());
        $this->assertStringContainsString('Nessun elemento con questi filtri.', $testo);
        $this->assertStringContainsString('0 elementi', $testo);

        [, $cliente] = $this->createTenantUser([], 'cliente');
        $this->actingAsTenantUser($cliente);
        $this->get('/api/v1/exports/assets.pdf')->assertForbidden();
    }

    public function test_il_carattere_incorporato_mappa_i_glifi_come_le_metriche_di_dompdf(): void
    {
        // I numeri dei glifi vengono dal file .ufm di dompdf dello stesso carattere
        $normale = CarattereTrueType::daFile(base_path(ScrittorePdf::CARATTERI['normale']));
        $this->assertSame([36, 153, 162, 1916], [$normale->glifo(65), $normale->glifo(215), $normale->glifo(224), $normale->glifo(8364)]);
        $this->assertSame(0, $normale->glifo(0x1F600), 'un simbolo che il carattere non ha torna al glifo mancante');
        $this->assertTrue($normale->passoFisso);
        $this->assertFalse($normale->grassetto);
        $this->assertSame(602, $normale->larghezzaGlifo(36));
        $this->assertSame([928, -236], [$normale->ascesa, $normale->discesa]);
        $this->assertSame('DejaVuSansMono', $normale->nomePostScript);
        $this->assertEqualsWithDelta(8 * 0.602 * 10, $normale->larghezzaTesto('ALB-0001', 10), 0.01);
        $grassetto = CarattereTrueType::daFile(base_path(ScrittorePdf::CARATTERI['grassetto']));
        $this->assertTrue($grassetto->grassetto);
        $this->assertSame('DejaVuSansMono-Bold', $grassetto->nomePostScript);

        // Lo scrittore: a capo alle parole, dentro la parola solo se non ci sta, testo rileggibile
        $pdf = new ScrittorePdf;
        $this->assertSame(['Parco Demo -', 'settore nord'], $pdf->spezza('Parco Demo - settore nord', 12 * 0.602 * 7 + 0.1, 7));
        $this->assertSame(['ABCDEFGHIJ', 'KLMNO'], $pdf->spezza('ABCDEFGHIJKLMNO', 10 * 0.602 * 7 + 0.1, 7));
        $pdf->nuovaPagina();
        $pdf->testo(20, 30, 'Città di prova € 1.234,50', 9);
        $pdf->testo(20, 50, 'In grassetto', 9, 'grassetto');
        $pdf->nuovaPagina();
        $pdf->testo(20, 30, 'Seconda pagina', 9);
        $pagine = LettorePdf::pagine($pdf->salva());
        $this->assertSame(["Città di prova € 1.234,50\nIn grassetto", 'Seconda pagina'], $pagine);
    }
}
