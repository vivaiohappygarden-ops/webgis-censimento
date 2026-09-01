<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Support\RicercaTestuale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Ricerca a parole.
 *
 * Chi cerca scrive come ricorda: "rossi mario" invece di "Mario Rossi", la
 * specie invece del codice di censimento. Prima il testo veniva confrontato
 * tutto intero e su pochissimi campi, e bastava invertire due parole per non
 * trovare piu' niente.
 */
class RicercaTest extends TestCase
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

    protected function tearDown(): void
    {
        // Un esito forzato del controllo sull'estensione non deve
        // sopravvivere al singolo test: la proprieta' statica resterebbe
        // per tutto il processo di PHPUnit
        RicercaTestuale::forzaSenzaAccenti(null);
        parent::tearDown();
    }

    private function committente(string $nome, array $extra = []): Client
    {
        return Client::create([
            'tenant_id' => $this->organizzazione->id,
            'name' => $nome,
            'client_type' => 'private',
            ...$extra,
        ]);
    }

    private function nomiCommittenti(string $cercato): array
    {
        return array_column(
            $this->getJson('/api/v1/clients?q='.urlencode($cercato))->assertOk()->json('data'),
            'name',
        );
    }

    // --- Le parole ---------------------------------------------------------

    public function test_una_parola_in_mezzo_al_nome_basta_a_trovarlo(): void
    {
        $this->committente('Mario Rossi');

        $this->assertSame(['Mario Rossi'], $this->nomiCommittenti('rossi'));
    }

    public function test_le_parole_si_possono_scrivere_in_qualunque_ordine(): void
    {
        $this->committente('Mario Rossi');

        $this->assertSame(['Mario Rossi'], $this->nomiCommittenti('rossi mario'));
    }

    public function test_bastano_pezzi_di_parola(): void
    {
        $this->committente('Mario Rossi');

        $this->assertSame(['Mario Rossi'], $this->nomiCommittenti('ros mar'));
    }

    public function test_devono_esserci_tutte_le_parole(): void
    {
        $this->committente('Mario Rossi');
        $this->committente('Luigi Verdi');

        // Ogni parola in piu' restringe: si cerca per arrivare a un risultato
        $this->assertSame([], $this->nomiCommittenti('rossi verdi'));
        $this->assertSame(['Mario Rossi'], $this->nomiCommittenti('rossi ma'));
    }

    public function test_maiuscole_e_minuscole_non_contano(): void
    {
        $this->committente('Mario Rossi');

        $this->assertSame(['Mario Rossi'], $this->nomiCommittenti('MARIO rOsSi'));
    }

    public function test_il_committente_si_trova_anche_per_partita_iva(): void
    {
        $this->committente('Comune di Guidonia', ['vat_number' => '01234567890']);

        $this->assertSame(['Comune di Guidonia'], $this->nomiCommittenti('0123456'));
    }

    public function test_i_caratteri_jolly_si_cercano_come_sono(): void
    {
        $this->committente('Sconto 50% primavera');
        $this->committente('Manutenzione ordinaria');

        // "%" dentro ILIKE vorrebbe dire "qualsiasi cosa": qui deve valere
        // come carattere scritto, altrimenti uscirebbero tutti
        $this->assertSame(['Sconto 50% primavera'], $this->nomiCommittenti('50%'));
    }

    public function test_troppe_parole_non_mandano_in_crisi_la_ricerca(): void
    {
        $this->committente('Mario Rossi');

        $tante = implode(' ', array_fill(0, 30, 'rossi'));
        $this->getJson('/api/v1/clients?q='.urlencode($tante))->assertOk();
        $this->assertCount(RicercaTestuale::MASSIMO_PAROLE, RicercaTestuale::parole($tante));
    }

    public function test_le_parole_si_preparano_per_il_confronto(): void
    {
        $this->assertSame([], RicercaTestuale::parole('   '));
        $this->assertSame([], RicercaTestuale::parole(null));
        $this->assertSame(['%mario%', '%rossi%'], RicercaTestuale::parole("  mario \t rossi "));
    }

    // --- Gli accenti -------------------------------------------------------

    public function test_gli_accenti_non_contano(): void
    {
        $this->serveSenzaAccenti();
        $this->committente('Città di Cefalù');

        // Sul telefono accento e apostrofo costano un tasto in più: si
        // scrive "citta" e si deve arrivare lo stesso a "Città"
        $this->assertSame(['Città di Cefalù'], $this->nomiCommittenti('citta cefalu'));
    }

    public function test_chi_scrive_l_accento_trova_anche_il_testo_senza(): void
    {
        $this->serveSenzaAccenti();
        $this->committente('Localita Pineta');

        // L'accento si toglie da tutte e due le parti del confronto
        $this->assertSame(['Localita Pineta'], $this->nomiCommittenti('località'));
    }

    public function test_maiuscole_e_accenti_insieme_non_contano(): void
    {
        $this->serveSenzaAccenti();
        $this->committente('Città di Cefalù');

        $this->assertSame(['Città di Cefalù'], $this->nomiCommittenti('CITTA cefalù'));
    }

    public function test_i_jolly_restano_schermati_anche_senza_accenti(): void
    {
        $this->serveSenzaAccenti();
        $this->committente('Sconto 50% però');
        $this->committente('Sconto 50 lordo');

        // "%" deve valere come carattere scritto anche quando il confronto
        // passa da senza_accenti(): unaccent non tocca jolly né schermature
        $this->assertSame(['Sconto 50% però'], $this->nomiCommittenti('50%'));
    }

    public function test_l_elemento_si_trova_senza_accenti_nelle_note(): void
    {
        $this->serveSenzaAccenti();
        $albero = $this->creaAlbero([], [
            'notes' => 'Da ricontrollare perché la chioma è sopra la città vecchia',
        ]);

        // Lo scope Asset::cercaTesto, dalla stessa API dell'elenco
        $this->assertSame([$albero], $this->idElementi('perche'));
        $this->assertSame([$albero], $this->idElementi('citta vecchia'));
    }

    public function test_senza_estensione_la_ricerca_distingue_ancora_gli_accenti(): void
    {
        $this->committente('Città di Cefalù');

        // Il ripiego dove unaccent manca: identico al comportamento vecchio.
        // L'esito del controllo si forza, senza togliere davvero l'estensione;
        // il tearDown lo azzera
        RicercaTestuale::forzaSenzaAccenti(false);

        $this->assertSame([], $this->nomiCommittenti('citta'));
        $this->assertSame(['Città di Cefalù'], $this->nomiCommittenti('città'));
    }

    /**
     * I test sugli accenti valgono solo dove il database ha la funzione
     * senza_accenti() (estensione unaccent più la sua migrazione): dove
     * manca, la ricerca degrada apposta al vecchio comportamento, che ha il
     * suo test con l'esito forzato.
     */
    private function serveSenzaAccenti(): void
    {
        if (! RicercaTestuale::databaseSenzaAccenti()) {
            $this->markTestSkipped('Estensione unaccent assente: qui la ricerca distingue ancora gli accenti.');
        }
    }

    // --- Gli elementi censiti ---------------------------------------------

    public function test_l_elemento_si_trova_per_specie_e_nome_comune(): void
    {
        $asset = $this->creaAlbero(['genus' => 'Quercus', 'species' => 'Quercus robur', 'common_name' => 'Farnia']);

        // La pagina VTA prometteva "cerca per codice, specie o note" e la
        // specie non veniva cercata affatto
        $this->assertSame([$asset], $this->idElementi('quercus'));
        $this->assertSame([$asset], $this->idElementi('farnia'));
        $this->assertSame([$asset], $this->idElementi('robur quercus'));
    }

    public function test_le_parole_possono_stare_in_campi_diversi(): void
    {
        // Il caso che conta: "farnia" e' la specie, "test" e' il nome
        // dell'area. Nessun campo contiene tutte e due, ma l'elemento e' quello
        $farnia = $this->creaAlbero(['common_name' => 'Farnia']);
        $this->creaAlbero(['common_name' => 'Tiglio']);

        $this->assertSame([$farnia], $this->idElementi('farnia test'));
        $this->assertSame([$farnia], $this->idElementi('test farnia'));
    }

    public function test_l_elemento_si_trova_per_area_localita_e_committente(): void
    {
        $asset = $this->creaAlbero();

        $this->assertSame([$asset], $this->idElementi('Area Test'));
        $this->assertSame([$asset], $this->idElementi('Localita'));
        $this->assertSame([$asset], $this->idElementi('Cliente Test'));
    }

    public function test_l_elemento_si_trova_ancora_per_codice_e_note(): void
    {
        $asset = $this->creaAlbero([], ['census_code' => 'ALB-0042', 'notes' => 'vicino alla fontana']);

        $this->assertSame([$asset], $this->idElementi('ALB-0042'));
        $this->assertSame([$asset], $this->idElementi('fontana'));
    }

    public function test_il_csv_esporta_quello_che_si_vede(): void
    {
        $this->creaAlbero(['common_name' => 'Farnia']);
        $this->creaAlbero(['common_name' => 'Tiglio']);

        $elenco = $this->idElementi('farnia');
        $csv = $this->get('/api/v1/exports/assets.csv?q=farnia')->assertOk()->streamedContent();

        $this->assertCount(1, $elenco);
        $this->assertSame(1, substr_count($csv, "\n") - 1, 'Il CSV deve avere le stesse righe dell\'elenco.');
    }

    public function test_la_ricerca_rapida_trova_anche_i_committenti(): void
    {
        $this->committente('Mario Rossi');

        $dati = $this->getJson('/api/v1/search?q='.urlencode('rossi mario'))->assertOk()->json('data');

        $this->assertSame(['Mario Rossi'], array_column($dati['clients'], 'name'));
    }

    public function test_la_ricerca_rapida_trova_l_albero_per_specie(): void
    {
        $asset = $this->creaAlbero(['common_name' => 'Farnia']);

        $dati = $this->getJson('/api/v1/search?q=farnia')->assertOk()->json('data');

        $this->assertSame([$asset], array_column($dati['assets'], 'id'));
    }

    public function test_la_ricerca_non_esce_dall_organizzazione(): void
    {
        $this->committente('Mario Rossi');

        [, $altro] = $this->createTenantUser();
        $this->actingAsTenantUser($altro);

        $this->assertSame([], $this->nomiCommittenti('rossi'));
        $this->assertSame([], $this->getJson('/api/v1/search?q=rossi')->assertOk()->json('data.clients'));
    }

    public function test_la_ricerca_rapida_non_mostra_i_committenti_a_chi_non_puo_vederli(): void
    {
        $this->committente('Mario Rossi');

        // L'operatore ha assets.view (la rotta della ricerca rapida chiede
        // quello) ma non l'anagrafica dei committenti
        [, $operatore] = $this->createTenantUser(role: 'operatore');
        $this->actingAsTenantUser($operatore);

        $dati = $this->getJson('/api/v1/search?q=rossi')->assertOk()->json('data');

        $this->assertSame([], $dati['clients']);
    }

    public function test_un_testo_illeggibile_lo_dice_invece_di_rompersi(): void
    {
        $this->committente('Mario Rossi');

        // Byte non validi in UTF-8: PostgreSQL rifiuta la query e la pagina
        // rispondeva "errore del programma". Ora e' un messaggio comprensibile
        $rotto = "\xC3\x28rossi";

        $this->getJson('/api/v1/clients?q='.rawurlencode($rotto))
            ->assertStatus(422)
            ->assertSee('non si possono leggere', false);

        // E se un testo del genere arrivasse comunque fin qui, il filtro non
        // sparirebbe lasciando uscire tutto l'elenco
        $this->assertNotSame([], RicercaTestuale::parole($rotto));
    }

    /** @return list<string> id degli elementi trovati */
    private function idElementi(string $cercato): array
    {
        return array_column(
            $this->getJson('/api/v1/assets?q='.urlencode($cercato))->assertOk()->json('data'),
            'id',
        );
    }

    private function creaAlbero(array $albero = [], array $elemento = []): string
    {
        $area = $this->area ??= $this->createArea($this->organizzazione);
        $tipo = $this->tipo ??= $this->makeObjectType($this->organizzazione, 'P', 'P103108');

        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $tipo->id,
            'geometry' => $this->pointGeometry(),
            ...$elemento,
        ])->assertCreated()->json('data.id');

        if ($albero !== []) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $albero])->assertOk();
        }

        return $id;
    }

    private $area = null;

    private $tipo = null;
}
