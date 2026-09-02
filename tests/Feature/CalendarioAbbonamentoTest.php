<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Client;
use App\Models\Inspection;
use App\Models\InspectionTemplate;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Calendario da abbonamento: il gettone personale e il feed iCal.
 *
 * Il feed vive fuori dal gruppo "web" (niente sessione, niente cookie) e
 * l'unico riconoscimento è il gettone nell'indirizzo: per questo i test
 * insistono su revoca (rigenerazione), permessi, separazione dei tenant e
 * forma del file (CRLF, righe a 75 byte, escaping).
 */
class CalendarioAbbonamentoTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $amministratore;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->organizzazione, $this->amministratore] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->actingAsTenantUser($this->amministratore);
    }

    /** L'indirizzo personale del feed (la GET del pannello crea il gettone). */
    private function urlFeed(?User $utente = null): string
    {
        if ($utente !== null) {
            $this->actingAsTenantUser($utente);
        }
        $url = $this->getJson('/api/v1/calendario/gettone')
            ->assertOk()->json('data.url');

        // Si interroga il percorso: l'host dell'ambiente di test non conta
        return parse_url($url, PHP_URL_PATH);
    }

    /** Riunisce le righe piegate a 75 byte, per le verifiche sul contenuto. */
    private function senzaPieghe(string $ics): string
    {
        return str_replace("\r\n ", '', $ics);
    }

    /** Il blocco VEVENT con quel UID, già senza pieghe. */
    private function blocco(string $ics, string $uid): string
    {
        foreach (explode('BEGIN:VEVENT', $this->senzaPieghe($ics)) as $pezzo) {
            if (str_contains($pezzo, 'UID:'.$uid)) {
                return $pezzo;
            }
        }

        $this->fail("Nessun VEVENT con UID {$uid} nel feed.");
    }

    private function creaLavoro(array $attributi = []): WorkOrder
    {
        return WorkOrder::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => WorkOrder::nextCode($this->organizzazione->id),
            'title' => 'Potatura siepi',
            'status' => 'planned',
            'area_id' => $this->area->id,
            'planned_start' => now('Europe/Rome')->addDays(5)->toDateString(),
            'planned_end' => now('Europe/Rome')->addDays(7)->toDateString(),
            'created_by' => $this->amministratore->id,
            'updated_by' => $this->amministratore->id,
            ...$attributi,
        ]);
    }

    /** Albero con valutazione VTA e ricontrollo alla data indicata (via API, da amministratore). */
    private function creaRicontrolloVta(string $scadenza): void
    {
        $tipoAlbero = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/assets/{$id}/assessments", [
            'assessment_type' => 'vta_visual',
            'assessed_on' => now('Europe/Rome')->subDays(10)->toDateString(),
            'failure_class' => 'B',
            'next_check_due' => $scadenza,
        ])->assertCreated();
    }

    private function creaPatentino(array $attributi = []): Certificate
    {
        return Certificate::create([
            'tenant_id' => $this->organizzazione->id,
            'holder_name' => 'Mario Verdi',
            'title' => 'Corso antincendio',
            'expires_on' => now('Europe/Rome')->addDays(30)->toDateString(),
            'created_by' => $this->amministratore->id,
            'updated_by' => $this->amministratore->id,
            ...$attributi,
        ]);
    }

    public function test_il_gettone_nasce_alla_prima_apertura_e_resta_stabile(): void
    {
        $this->assertNull($this->amministratore->fresh()->calendar_token);

        $percorso = $this->urlFeed();
        $this->assertMatchesRegularExpression('#^/calendario/[A-Za-z0-9_-]{40,}\.ics$#', $percorso);

        // La seconda apertura del pannello non cambia l'indirizzo
        $this->assertSame($percorso, $this->urlFeed());

        // Il gettone non esce mai dalla serializzazione del modello: gli
        // elenchi utenti non devono regalare l'agenda dei colleghi
        $this->assertArrayNotHasKey('calendar_token', $this->amministratore->fresh()->toArray());
    }

    public function test_rigenerare_il_gettone_revoca_il_vecchio_indirizzo(): void
    {
        $vecchio = $this->urlFeed();
        $this->get($vecchio)->assertOk();

        $nuovo = parse_url($this->postJson('/api/v1/calendario/gettone/rigenera')
            ->assertOk()->json('data.url'), PHP_URL_PATH);

        $this->assertNotSame($vecchio, $nuovo);
        $this->get($vecchio)->assertNotFound();
        $this->get($nuovo)->assertOk();
    }

    public function test_gettone_ignoto_404(): void
    {
        // Formato valido ma mai emesso: 404 muto, nessuna spiegazione
        $this->get('/calendario/'.str_repeat('a', 48).'.ics')->assertNotFound();
        // Troppo corto per il vincolo di rotta: nemmeno arriva al controller
        $this->get('/calendario/breve.ics')->assertNotFound();
    }

    public function test_utente_disattivato_404(): void
    {
        $percorso = $this->urlFeed();
        $this->amministratore->forceFill(['is_active' => false])->save();

        $this->get($percorso)->assertNotFound();
    }

    public function test_il_feed_ha_forma_ical_corretta_e_non_lascia_cookie(): void
    {
        $this->creaLavoro(['client_id' => Client::create([
            'tenant_id' => $this->organizzazione->id,
            'name' => 'Committente con un nome piuttosto lungo che costringe a piegare la riga della descrizione',
            'client_type' => 'private',
        ])->id]);

        $risposta = $this->get($this->urlFeed());
        $risposta->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        // Niente cookie: la rotta vive fuori dal gruppo web apposta
        $this->assertCount(0, $risposta->baseResponse->headers->getCookies());

        $ics = $risposta->getContent();
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("\r\nMETHOD:PUBLISH\r\n", $ics);
        $this->assertStringContainsString('X-WR-CALNAME:Verde - agenda e scadenze', $ics);

        // Ogni a capo è CRLF: un LF nudo rompe alcuni lettori
        $this->assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $ics);

        // Nessuna riga sopra i 75 byte (RFC 5545): la descrizione lunga di
        // proposito deve risultare piegata
        foreach (explode("\r\n", $ics) as $riga) {
            $this->assertLessThanOrEqual(75, strlen($riga), "Riga oltre i 75 byte: {$riga}");
        }
        $this->assertStringContainsString("\r\n ", $ics);
    }

    public function test_lavoro_pianificato_con_date_giuste_titolo_schermato_e_termine(): void
    {
        $committente = Client::create([
            'tenant_id' => $this->organizzazione->id,
            'name' => 'Comune di Collaudo',
            'client_type' => 'private',
        ]);
        $lavoro = $this->creaLavoro([
            'title' => 'Potatura, con urgenza',
            'client_id' => $committente->id,
            'due_at' => now('Europe/Rome')->addDays(20)->setTime(12, 0),
        ]);

        $ics = $this->get($this->urlFeed())->assertOk()->getContent();

        // Giornate intere: DTEND esclusivo, quindi il giorno dopo la fine
        $evento = $this->blocco($ics, "lavoro-{$lavoro->id}@webgis");
        $this->assertStringContainsString(
            'SUMMARY:Potatura\, con urgenza - Area Test ('.$lavoro->code.')', $evento);
        $this->assertStringContainsString(
            'DTSTART;VALUE=DATE:'.now('Europe/Rome')->addDays(5)->format('Ymd'), $evento);
        $this->assertStringContainsString(
            'DTEND;VALUE=DATE:'.now('Europe/Rome')->addDays(8)->format('Ymd'), $evento);
        $this->assertStringContainsString(
            'DESCRIPTION:Stato: Pianificato\nCommittente: Comune di Collaudo\nArea: Area Test',
            $evento);

        // Il termine ultimo è un evento a sé, il giorno della scadenza
        $termine = $this->blocco($ics, "lavoro-termine-{$lavoro->id}@webgis");
        $this->assertStringContainsString(
            'SUMMARY:Termine '.$lavoro->code.' - Potatura\, con urgenza', $termine);
        $this->assertStringContainsString(
            'DTSTART;VALUE=DATE:'.now('Europe/Rome')->addDays(20)->format('Ymd'), $termine);
    }

    public function test_lavori_chiusi_o_annullati_non_entrano(): void
    {
        $this->creaLavoro(['status' => 'completed', 'title' => 'Lavoro chiuso']);
        $this->creaLavoro(['status' => 'cancelled', 'title' => 'Lavoro annullato']);

        $ics = $this->senzaPieghe($this->get($this->urlFeed())->assertOk()->getContent());
        $this->assertStringNotContainsString('Lavoro chiuso', $ics);
        $this->assertStringNotContainsString('Lavoro annullato', $ics);
        $this->assertStringNotContainsString('UID:lavoro-', $ics);
    }

    public function test_scadenza_vta_e_patentino_entrano(): void
    {
        $scadenzaVta = now('Europe/Rome')->addDays(40)->toDateString();
        $this->creaRicontrolloVta($scadenzaVta);
        $patentino = $this->creaPatentino();

        $ics = $this->get($this->urlFeed())->assertOk()->getContent();

        $senzaPieghe = $this->senzaPieghe($ics);
        $this->assertStringContainsString('SUMMARY:Ricontrollo VTA - ', $senzaPieghe);
        $this->assertStringContainsString(
            'DTSTART;VALUE=DATE:'.now('Europe/Rome')->addDays(40)->format('Ymd'), $senzaPieghe);

        $evento = $this->blocco($ics, "patentino-{$patentino->id}@webgis");
        $this->assertStringContainsString('SUMMARY:Scadenza Corso antincendio - Mario Verdi', $evento);
        $this->assertStringContainsString(
            'DTSTART;VALUE=DATE:'.now('Europe/Rome')->addDays(30)->format('Ymd'), $evento);
    }

    public function test_ispezione_ricorrente_entra_con_la_scadenza_calcolata(): void
    {
        $modello = InspectionTemplate::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => 'CTRL01',
            'name' => 'Controllo aree gioco',
            'target' => 'area',
            'frequency_days' => 90,
            'is_active' => true,
        ]);
        Inspection::create([
            'tenant_id' => $this->organizzazione->id,
            'template_id' => $modello->id,
            'template_version' => 1,
            'area_id' => $this->area->id,
            'inspector_id' => $this->amministratore->id,
            'started_at' => now('Europe/Rome')->subDays(30)->setTime(10, 0),
            'completed_at' => now('Europe/Rome')->subDays(30)->setTime(10, 30),
            'outcome' => 'passed',
        ]);

        $ics = $this->get($this->urlFeed())->assertOk()->getContent();

        // Ultima ispezione + periodicità: la stessa data dello scadenzario
        $evento = $this->blocco($ics, "ispezione-{$modello->id}-{$this->area->id}@webgis");
        $this->assertStringContainsString('SUMMARY:Ispezione Controllo aree gioco - Area Test', $evento);
        $this->assertStringContainsString(
            'DTSTART;VALUE=DATE:'.now('Europe/Rome')->subDays(30)->startOfDay()->addDays(90)->format('Ymd'),
            $evento);
    }

    public function test_senza_works_view_niente_lavori_ne_patentini(): void
    {
        $this->creaLavoro(['title' => 'Lavoro dello staff']);
        $this->creaPatentino();
        $this->creaRicontrolloVta(now('Europe/Rome')->addDays(40)->toDateString());

        // Solo censimento: assets.view diretto, nessun ruolo con works.view
        $soloCensimento = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $soloCensimento->givePermissionTo('assets.view');

        $ics = $this->senzaPieghe($this->get($this->urlFeed($soloCensimento))->assertOk()->getContent());

        $this->assertStringNotContainsString('UID:lavoro-', $ics);
        $this->assertStringNotContainsString('Lavoro dello staff', $ics);
        $this->assertStringNotContainsString('UID:patentino-', $ics);
        $this->assertStringContainsString('SUMMARY:Ricontrollo VTA - ', $ics);
    }

    public function test_l_operatore_vede_solo_i_suoi_lavori(): void
    {
        $operatore = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $operatore->assignRole('operatore');

        $mio = $this->creaLavoro([
            'status' => 'assigned', 'assigned_to' => $operatore->id, 'title' => 'Sfalcio parco mio',
        ]);
        $altrui = $this->creaLavoro([
            'status' => 'assigned', 'assigned_to' => $this->amministratore->id, 'title' => 'Sfalcio parco altrui',
        ]);

        $ics = $this->senzaPieghe($this->get($this->urlFeed($operatore))->assertOk()->getContent());
        $this->assertStringContainsString($mio->code, $ics);
        $this->assertStringNotContainsString($altrui->code, $ics);
        $this->assertStringNotContainsString('Sfalcio parco altrui', $ics);

        // Chi gestisce, invece, li vede tutti e due
        $tutto = $this->senzaPieghe($this->get($this->urlFeed($this->amministratore))->assertOk()->getContent());
        $this->assertStringContainsString($mio->code, $tutto);
        $this->assertStringContainsString($altrui->code, $tutto);
    }

    public function test_il_feed_non_mescola_mai_i_tenant(): void
    {
        $this->creaLavoro(['title' => 'Lavoro del tenant A']);
        $this->creaPatentino(['holder_name' => 'Persona del tenant A']);

        [$orgB, $utenteB] = $this->createTenantUser();
        WorkOrder::create([
            'tenant_id' => $orgB->id,
            'code' => WorkOrder::nextCode($orgB->id),
            'title' => 'Abbattimento riservato B',
            'status' => 'planned',
            'planned_start' => now('Europe/Rome')->addDays(3)->toDateString(),
            'created_by' => $utenteB->id,
            'updated_by' => $utenteB->id,
        ]);
        Certificate::create([
            'tenant_id' => $orgB->id,
            'holder_name' => 'Persona del tenant B',
            'title' => 'Patentino muletto',
            'expires_on' => now('Europe/Rome')->addDays(15)->toDateString(),
        ]);

        // Il feed di A non contiene NULLA di B, e viceversa
        $icsA = $this->senzaPieghe($this->get($this->urlFeed($this->amministratore))->assertOk()->getContent());
        $this->assertStringContainsString('Lavoro del tenant A', $icsA);
        $this->assertStringNotContainsString('Abbattimento riservato B', $icsA);
        $this->assertStringNotContainsString('Persona del tenant B', $icsA);

        $icsB = $this->senzaPieghe($this->get($this->urlFeed($utenteB))->assertOk()->getContent());
        $this->assertStringContainsString('Abbattimento riservato B', $icsB);
        $this->assertStringNotContainsString('Lavoro del tenant A', $icsB);
        $this->assertStringNotContainsString('Persona del tenant A', $icsB);
    }
}
