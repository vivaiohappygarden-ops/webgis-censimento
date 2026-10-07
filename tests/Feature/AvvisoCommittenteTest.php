<?php

namespace Tests\Feature;

use App\Mail\AvvisoCommittenteMail;
use App\Models\Area;
use App\Models\Client;
use App\Models\ClientAlert;
use App\Models\Locality;
use App\Models\Site;
use App\Models\User;
use App\Services\Pdf\PdfRenderer;
use App\Services\Vta\AvvisoCommittente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\Support\RaccoglitorePdf;
use Tests\TestCase;

/**
 * L'avviso al committente dalla VTA (richiesta del committente 07/10/2026):
 * un albero pericoloso in un parco giochi, il tecnico spunta l'avviso, il
 * testo entra fra le prescrizioni, l'email parte agli indirizzi del Comune
 * con copia al tecnico, il Comune ne prende atto dal portale riservato, il
 * tecnico segna il rientro; Oggi, scheda, cronologia e perizia lo dicono.
 */
class AvvisoCommittenteTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $tecnico;

    private Client $comune;

    private Area $parco;

    private User $ufficio;

    private string $albero;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        Http::fake(['tile.openstreetmap.org/*' => Http::response('', 500)]);
        [$this->organizzazione, $this->tecnico] = $this->createTenantUser(['email' => 'tecnico@studio.test']);
        $this->actingAsTenantUser($this->tecnico);

        $this->comune = Client::create(['tenant_id' => $this->organizzazione->id, 'name' => 'Comune di Prova', 'client_type' => 'public',
            'pec' => 'protocollo@pec.comune.test', 'contacts' => [['nome' => 'Ufficio verde', 'email' => 'verde@comune.test'], ['nome' => 'Senza email']]]);
        $sede = Site::create(['tenant_id' => $this->organizzazione->id, 'client_id' => $this->comune->id, 'name' => 'Sede']);
        $localita = Locality::create(['tenant_id' => $this->organizzazione->id, 'site_id' => $sede->id, 'name' => 'Centro']);
        $this->parco = $this->createArea($this->organizzazione, ['locality_id' => $localita->id, 'name' => 'Parco giochi di via Verdi', 'area_type' => 'functional']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $this->ufficio = User::factory()->create(['tenant_id' => $this->organizzazione->id, 'client_id' => $this->comune->id, 'email' => 'ufficio@comune.test', 'name' => 'Ufficio tecnico']);
        $this->ufficio->assignRole('cliente');
        // Lo stesso indirizzo scritto diversamente non fa due invii
        $doppione = User::factory()->create(['tenant_id' => $this->organizzazione->id, 'client_id' => $this->comune->id, 'email' => 'Verde@comune.test']);
        $doppione->assignRole('cliente');

        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->albero = $this->postJson('/api/v1/assets', ['area_id' => $this->parco->id, 'object_type_id' => $tipo->id, 'census_code' => 'ALB-0007', 'geometry' => $this->pointGeometry()])
            ->assertCreated()->json('data.id');
    }

    private function valutazione(array $extra = []): array
    {
        return $this->postJson("/api/v1/assets/{$this->albero}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => now('Europe/Rome')->toDateString(), 'failure_class' => 'D',
            'outcome' => 'prescriptions', 'prescriptions' => 'Abbattimento urgente', ...$extra,
        ])->assertCreated()->json('data');
    }

    public function test_i_destinatari_sono_quelli_del_committente_senza_doppioni_e_la_scheda_li_vede_prima(): void
    {
        $destinatari = AvvisoCommittente::destinatari($this->comune);
        $this->assertEqualsCanonicalizing(['ufficio@comune.test', 'Verde@comune.test', 'protocollo@pec.comune.test'], $destinatari->pluck('email')->all());
        $this->assertSame(['portale', 'portale', 'pec'], $destinatari->pluck('origine')->all());
        // Il contatto "verde@comune.test" e' lo stesso indirizzo dell'utente del portale: una volta sola

        $proposte = $this->getJson("/api/v1/assets/{$this->albero}/bersagli-proposti")->assertOk()->json('data');
        $this->assertSame('Comune di Prova', $proposte['committente']['nome']);
        $this->assertCount(3, $proposte['committente']['destinatari']);
    }

    public function test_l_avviso_entra_fra_le_prescrizioni_parte_via_email_e_resta_registrato(): void
    {
        $dati = $this->valutazione(['avviso_committente' => ['attivo' => true, 'area_id' => $this->parco->id]]);

        $testo = 'Interdire l\'accesso all\'area "Parco giochi di via Verdi" nel raggio di caduta dell\'albero ALB-0007 fino all\'esecuzione degli interventi prescritti.';
        $this->assertSame("Abbattimento urgente\n".$testo, $dati['prescriptions']);
        $this->assertSame($testo, $dati['avviso']['message']);
        $this->assertSame('D', $dati['avviso']['failure_class']);
        $this->assertSame('Parco giochi di via Verdi', $dati['avviso']['area']['name']);
        $this->assertSame(['inviata', 'inviata', 'inviata'], array_column($dati['avviso']['recipients'], 'esito'));

        // Un'email per destinatario, piu' la copia al tecnico; a nome dell'organizzazione
        Mail::assertSent(AvvisoCommittenteMail::class, 4);
        Mail::assertSent(AvvisoCommittenteMail::class, fn ($m) => $m->hasTo('ufficio@comune.test'));
        Mail::assertSent(AvvisoCommittenteMail::class, fn ($m) => $m->hasTo('protocollo@pec.comune.test'));
        Mail::assertSent(AvvisoCommittenteMail::class, fn ($m) => $m->hasTo('Verde@comune.test'));
        Mail::assertSent(AvvisoCommittenteMail::class, fn ($m) => $m->hasTo('tecnico@studio.test') && str_starts_with($m->envelope()->subject, 'Copia - '));
        Mail::assertSent(AvvisoCommittenteMail::class, function (AvvisoCommittenteMail $m) use ($testo) {
            if (! $m->hasTo('ufficio@comune.test')) {
                return false;
            }
            $html = $m->render();
            $this->assertStringContainsString(e($testo), $html);
            $this->assertStringContainsString('ALB-0007', $html);
            $this->assertStringContainsString('classe D', $html);
            $this->assertStringContainsString('/portale', $html);
            $this->assertSame($this->organizzazione->name, $m->envelope()->from->name);
            $this->assertStringContainsString('area da chiudere o interdire', $m->envelope()->subject);

            return true;
        });

        $avviso = ClientAlert::query()->firstOrFail();
        $this->assertSame($this->comune->id, $avviso->client_id);
        $this->assertSame($dati['id'], $avviso->assessment_id);
        $this->assertSame($this->tecnico->id, $avviso->sent_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'avviso.committente_inviato']);

        // Nelle valutazioni dell'albero e nella cronologia
        $elenco = $this->getJson("/api/v1/assets/{$this->albero}/assessments")->assertOk()->json('data');
        $this->assertSame($avviso->id, $elenco[0]['avviso']['id']);
        $cronologia = $this->getJson("/api/v1/assets/{$this->albero}/cronologia")->assertOk()->json('data.eventi');
        $riga = collect($cronologia)->firstWhere('tipo', 'avviso');
        $this->assertSame('Avviso al committente · Parco giochi di via Verdi', $riga['titolo']);
        $this->assertStringContainsString('3 indirizzi', $riga['dettaglio']);
        $this->assertStringContainsString('in attesa di presa d\'atto', $riga['dettaglio']);

        // Correggere la valutazione con la spunta ancora attiva non rimanda niente
        $this->patchJson("/api/v1/assessments/{$dati['id']}", ['version' => $dati['version'], 'outcome' => 'fell', 'avviso_committente' => ['attivo' => true]])->assertOk();
        $this->assertSame(1, ClientAlert::query()->count());
        Mail::assertSent(AvvisoCommittenteMail::class, 4);

        // La perizia lo scrive fra le prescrizioni
        $stampe = new RaccoglitorePdf;
        $this->app->instance(PdfRenderer::class, $stampe);
        $this->get("/api/v1/assessments/{$dati['id']}/perizia-pdf")->assertOk();
        $this->assertStringContainsString('Avviso al committente', $stampe->html['pdf.perizia']);
        $this->assertStringContainsString('Trasmesso il '.now('Europe/Rome')->format('d/m/Y'), $stampe->html['pdf.perizia']);
    }

    public function test_il_testo_scritto_dal_tecnico_vince_e_non_si_duplica_fra_le_prescrizioni(): void
    {
        $dati = $this->valutazione(['prescriptions' => "Potatura di alleggerimento\nChiudere il parco fino alla potatura", 'avviso_committente' => ['attivo' => true, 'testo' => '  Chiudere il parco fino alla potatura ']]);
        $this->assertSame("Potatura di alleggerimento\nChiudere il parco fino alla potatura", $dati['prescriptions']);
        $this->assertSame('Chiudere il parco fino alla potatura', $dati['avviso']['message']);
        $this->assertNull($dati['avviso']['area']);
    }

    public function test_senza_indirizzi_l_avviso_resta_nel_portale_e_nessuna_email_parte(): void
    {
        $this->comune->update(['pec' => null, 'contacts' => []]);
        User::query()->where('client_id', $this->comune->id)->update(['is_active' => false]);

        $dati = $this->valutazione(['avviso_committente' => ['attivo' => true]]);
        $this->assertSame([], $dati['avviso']['recipients']);
        Mail::assertNothingSent();
        $this->assertSame(1, ClientAlert::query()->count());
    }

    public function test_il_comune_lo_vede_nel_portale_riservato_e_ne_prende_atto_poi_il_tecnico_segna_il_rientro(): void
    {
        $dati = $this->valutazione(['avviso_committente' => ['attivo' => true, 'area_id' => $this->parco->id]]);
        $id = $dati['avviso']['id'];

        // In Oggi finche' non rientra: senza presa d'atto e' una cosa da sollecitare
        $oggi = $this->getJson('/api/v1/oggi')->assertOk()->json('data');
        $this->assertSame(1, $oggi['conteggi']['avvisi_aperti']);
        $this->assertSame(1, $oggi['conteggi']['avvisi_senza_presa_atto']);
        $voce = collect($oggi['voci'])->firstWhere('tipo', 'avviso');
        $this->assertSame('ALB-0007 · avviso al committente Comune di Prova', $voce['titolo']);
        $this->assertSame('presto', $voce['urgenza']);

        // L'ufficio tecnico del Comune: lo legge e ne prende atto con una nota
        $this->actingAsTenantUser($this->ufficio);
        $avvisi = $this->getJson('/api/v1/portal/avvisi')->assertOk()->json('data');
        $this->assertCount(1, $avvisi);
        $this->assertSame('ALB-0007', $avvisi[0]['albero']['cartellino']);
        $this->assertSame('Parco giochi di via Verdi', $avvisi[0]['area']);
        $this->assertNull($avvisi[0]['presa_atto_il']);
        $presa = $this->postJson("/api/v1/portal/avvisi/{$id}/presa-atto", ['nota' => 'Ordinanza n. 12 del '.now('Europe/Rome')->format('d/m/Y')])->assertOk()->json('data');
        $this->assertNotNull($presa['presa_atto_il']);
        $this->assertSame('Ufficio tecnico', $presa['presa_atto_da']);
        $this->assertStringStartsWith('Ordinanza n. 12', $presa['presa_atto_nota']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'avviso.presa_atto']);
        // Il portale non tocca le chiamate del gestionale
        $this->postJson("/api/v1/avvisi/{$id}/rientro")->assertForbidden();

        // Un altro Comune non lo vede e non puo' prenderne atto
        $altro = Client::create(['tenant_id' => $this->organizzazione->id, 'name' => 'Altro Comune', 'client_type' => 'public']);
        $estraneo = User::factory()->create(['tenant_id' => $this->organizzazione->id, 'client_id' => $altro->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $estraneo->assignRole('cliente');
        $this->actingAsTenantUser($estraneo);
        $this->assertSame([], $this->getJson('/api/v1/portal/avvisi')->assertOk()->json('data'));
        $this->postJson("/api/v1/portal/avvisi/{$id}/presa-atto")->assertNotFound();

        // Il tecnico vede la presa d'atto e segna il rientro
        $this->actingAsTenantUser($this->tecnico);
        $oggi = $this->getJson('/api/v1/oggi')->assertOk()->json('data');
        $this->assertSame(0, $oggi['conteggi']['avvisi_senza_presa_atto']);
        $this->assertSame('programma', collect($oggi['voci'])->firstWhere('tipo', 'avviso')['urgenza']);
        $rientro = $this->postJson("/api/v1/avvisi/{$id}/rientro", ['nota' => 'Albero abbattuto, area riaperta'])->assertOk()->json('data');
        $this->assertNotNull($rientro['resolved_at']);
        $this->assertSame('Albero abbattuto, area riaperta', $rientro['resolved_note']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'avviso.committente_rientrato']);
        $this->assertSame(0, $this->getJson('/api/v1/oggi')->json('data.conteggi.avvisi_aperti'));
        $elenco = $this->getJson("/api/v1/assets/{$this->albero}/assessments")->assertOk()->json('data');
        $this->assertNotNull($elenco[0]['avviso']['resolved_at']);
        $this->assertSame($this->ufficio->name, $elenco[0]['avviso']['acknowledger']['name']);

        // Rientrato di recente: il Comune lo vede ancora, come chiuso
        $this->actingAsTenantUser($this->ufficio);
        $this->assertNotNull($this->getJson('/api/v1/portal/avvisi')->json('data.0.rientrato_il'));
    }

    public function test_senza_spunta_niente_avviso_e_la_vegetazione_senza_committente_non_si_avvisa(): void
    {
        $dati = $this->valutazione();
        $this->assertNull($dati['avviso']);
        Mail::assertNothingSent();
        $this->assertSame(0, ClientAlert::query()->count());
        $this->postJson("/api/v1/assets/{$this->albero}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => now('Europe/Rome')->toDateString(), 'failure_class' => 'D',
            'avviso_committente' => ['attivo' => true, 'testo' => str_repeat('x', 1001)],
        ])->assertStatus(422);
    }
}
