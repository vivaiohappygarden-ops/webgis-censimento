<?php

namespace Tests\Feature;

use App\Services\Export\FoglioXlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * L'esportazione in foglio Excel vero (.xlsx).
 *
 * Il CSV Excel lo apre, ma non e' un foglio: i numeri con la virgola
 * diventano testo o date secondo come e' configurato il computer di chi
 * apre. Qui si controlla che il file sia un .xlsx valido, che i numeri siano
 * numeri e le date siano date, e che contenga esattamente quello che
 * contiene il CSV: le due strade partono dalle stesse colonne.
 */
class EsportaFoglioExcelTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    private $tipoAlbero;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->tipoAlbero = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);
    }

    private function creaAlbero(array $scheda = [], array $campi = []): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
            ...$campi,
        ])->assertCreated()->json('data.id');

        if ($scheda !== []) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $scheda])->assertOk();
        }

        return $id;
    }

    /** Apre il file scaricato e restituisce i pezzi che contano. */
    private function apri(string $contenuto): array
    {
        $percorso = tempnam(sys_get_temp_dir(), 'prova_').'.xlsx';
        file_put_contents($percorso, $contenuto);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($percorso) === true, 'Il file deve essere un archivio valido');

        $parti = [];
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $nome) {
            $parti[$nome] = $zip->getFromName($nome);
            $this->assertIsString($parti[$nome], "Manca la parte {$nome}");
        }
        $zip->close();
        @unlink($percorso);

        return $parti;
    }

    public function test_il_file_scaricato_e_un_foglio_excel_valido(): void
    {
        $this->creaAlbero(['species' => 'Tilia cordata', 'dbh_cm' => 38]);

        $risposta = $this->get('/api/v1/exports/assets.xlsx')->assertOk();
        $this->assertSame(FoglioXlsx::mime(), $risposta->headers->get('content-type'));
        $this->assertStringContainsString('.xlsx', (string) $risposta->headers->get('content-disposition'));

        $parti = $this->apri($risposta->streamedContent());

        // Un XML rotto qui vuol dire un file che Excel rifiuta di aprire
        foreach ($parti as $nome => $xml) {
            $this->assertNotFalse(simplexml_load_string($xml), "XML non valido in {$nome}");
        }
        $this->assertStringContainsString('Censimento', $parti['xl/workbook.xml']);
    }

    public function test_l_intestazione_e_bloccata_e_filtrabile(): void
    {
        $this->creaAlbero();

        $foglio = $this->apri($this->get('/api/v1/exports/assets.xlsx')->assertOk()->streamedContent())['xl/worksheets/sheet1.xml'];

        // Sono i due motivi per cui si chiede un foglio invece di un CSV
        $this->assertStringContainsString('state="frozen"', $foglio);
        $this->assertStringContainsString('<autoFilter', $foglio);
        $this->assertStringContainsString('Codice', $foglio);
        $this->assertStringContainsString('Diametro fusto (cm)', $foglio);
    }

    public function test_i_numeri_sono_numeri_e_le_date_sono_date(): void
    {
        $this->creaAlbero(
            ['species' => 'Tilia cordata', 'dbh_cm' => 38.5, 'height_m' => 12],
            ['surveyed_at' => '2026-03-15'],
        );

        $foglio = $this->apri($this->get('/api/v1/exports/assets.xlsx')->assertOk()->streamedContent())['xl/worksheets/sheet1.xml'];

        // Il diametro come numero (niente virgola, niente testo): Excel deve
        // poterlo sommare e ordinare
        $this->assertMatchesRegularExpression('/<c r="M2"><v>38\.5<\/v><\/c>/', $foglio);
        // La data come numero seriale con lo stile della data italiana:
        // 15/03/2026 sono 46096 giorni dal 30/12/1899
        $this->assertMatchesRegularExpression('/<c r="I2" s="2"><v>46096<\/v><\/c>/', $foglio);
    }

    public function test_il_testo_resta_testo_anche_quando_sembra_una_formula(): void
    {
        // Nel CSV una cella che comincia con "=" va protetta; nel foglio no,
        // perche' una cella di testo non diventa una formula. Il contenuto
        // pero' deve arrivare intero, senza apostrofi aggiunti
        $this->creaAlbero([], ['notes' => '=SOMMA(A1:A9) da verificare']);

        $foglio = $this->apri($this->get('/api/v1/exports/assets.xlsx')->assertOk()->streamedContent())['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('=SOMMA(A1:A9) da verificare', $foglio);
        $this->assertStringNotContainsString("'=SOMMA", $foglio);
    }

    public function test_il_foglio_esporta_le_stesse_righe_del_csv(): void
    {
        $this->creaAlbero(['species' => 'Tilia cordata']);
        $this->creaAlbero(['species' => 'Acer campestre']);
        $nascosto = $this->creaAlbero(['species' => 'Quercus ilex']);
        // Un filtro attivo vale per tutti e due i formati: si esporta quello
        // che si sta guardando
        $this->patchJson("/api/v1/assets/{$nascosto}", ['status' => 'dismissed'])->assertOk();

        // archivio=0: l'elenco a video di serie nasconde dismessi e abbattuti,
        // e l'esportazione deve dare quello che si vede
        $csv = $this->get('/api/v1/exports/assets.csv?archivio=0')->assertOk()->streamedContent();
        $foglio = $this->apri(
            $this->get('/api/v1/exports/assets.xlsx?archivio=0')->assertOk()->streamedContent()
        )['xl/worksheets/sheet1.xml'];

        foreach (['Tilia cordata', 'Acer campestre'] as $specie) {
            $this->assertStringContainsString($specie, $csv);
            $this->assertStringContainsString($specie, $foglio);
        }
        $this->assertStringNotContainsString('Quercus ilex', $csv);
        $this->assertStringNotContainsString('Quercus ilex', $foglio, 'Il dismesso e fuori da tutti e due');
    }

    public function test_la_ricerca_vale_anche_per_il_foglio(): void
    {
        $this->creaAlbero(['species' => 'Tilia cordata']);
        $this->creaAlbero(['species' => 'Acer campestre']);

        $foglio = $this->apri(
            $this->get('/api/v1/exports/assets.xlsx?q=tilia')->assertOk()->streamedContent()
        )['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('Tilia cordata', $foglio);
        $this->assertStringNotContainsString('Acer campestre', $foglio);
    }

    public function test_senza_permesso_non_si_esporta(): void
    {
        [$organizzazione, $cliente] = $this->createTenantUser([], 'cliente');
        $this->actingAsTenantUser($cliente);

        $this->get('/api/v1/exports/assets.xlsx')->assertForbidden();
    }

    public function test_il_foglio_regge_un_testo_con_caratteri_strani(): void
    {
        // Accenti, virgolette, e-commerciale e un carattere di controllo:
        // il primo che rompe l'XML rende il file illeggibile
        $this->creaAlbero([], ['notes' => "Città \"alberata\" & viale <nuovo>\x07"]);

        $parti = $this->apri($this->get('/api/v1/exports/assets.xlsx')->assertOk()->streamedContent());
        $xml = simplexml_load_string($parti['xl/worksheets/sheet1.xml']);

        $this->assertNotFalse($xml);
        $this->assertStringContainsString('Città &quot;alberata&quot; &amp; viale &lt;nuovo&gt;', $parti['xl/worksheets/sheet1.xml']);
    }
}
