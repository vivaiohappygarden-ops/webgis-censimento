<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\CatalogObjectType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * La storia delle modifiche della scheda (GET /assets/{id}/versioni).
 *
 * Il punto delicato e' l'ordine delle scritture: la fotografia di versione
 * scatta sull'aggiornamento della riga assets e deve riprendere la scheda
 * albero com'era PRIMA del salvataggio. Se qualcuno invertisse l'ordine in
 * AssetController::update, il test della specie fallirebbe mostrando lo
 * stesso valore su "prima" e "dopo".
 */
class StoriaSchedaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Area $area;

    private CatalogObjectType $pointType;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser(['name' => 'Tecnico Prova']);
        $this->area = $this->createArea($this->organization);
        $this->pointType = $this->makeObjectType($this->organization, 'P', 'P103108');

        $this->actingAsTenantUser($this->user);
    }

    public function test_le_modifiche_ai_campi_della_scheda_finiscono_nella_storia(): void
    {
        $id = $this->creaElemento();

        $this->patchJson("/api/v1/assets/{$id}", ['notes' => 'Da controllare'])->assertOk();
        $this->patchJson("/api/v1/assets/{$id}", [
            'notes' => 'Controllo eseguito',
            'surveyed_at' => '2026-08-20',
        ])->assertOk();

        $storia = $this->getJson("/api/v1/assets/{$id}/versioni")->assertOk()->json('data');

        $this->assertCount(2, $storia);

        // La revisione piu' recente sta in cima, con autore, data e origine
        $ultima = $storia[0];
        $this->assertSame('Tecnico Prova', $ultima['chi']);
        $this->assertSame('web', $ultima['origine']);
        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', $ultima['quando']);

        $campi = collect($ultima['modifiche'])->keyBy('campo');
        $this->assertSame('Da controllare', $campi['Note']['prima']);
        $this->assertSame('Controllo eseguito', $campi['Note']['dopo']);
        $this->assertSame('20/08/2026', $campi['Data di rilievo']['dopo']);

        $prima = collect($storia[1]['modifiche'])->firstWhere('campo', 'Note');
        $this->assertNull($prima['prima']);
        $this->assertSame('Da controllare', $prima['dopo']);
    }

    public function test_la_storia_mostra_i_valori_precedenti_della_scheda_albero(): void
    {
        $id = $this->creaElemento();

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['species' => 'Tilia cordata', 'height_m' => 12],
        ])->assertOk();
        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['species' => 'Quercus robur', 'height_m' => 14.5],
        ])->assertOk();

        $storia = $this->getJson("/api/v1/assets/{$id}/versioni")->assertOk()->json('data');

        $this->assertCount(2, $storia);

        $campi = collect($storia[0]['modifiche'])->keyBy('campo');
        $this->assertSame('Tilia cordata', $campi['Specie']['prima']);
        $this->assertSame('Quercus robur', $campi['Specie']['dopo']);
        $this->assertEqualsWithDelta(12, (float) $campi['Altezza (m)']['prima'], 0.001);
        $this->assertEqualsWithDelta(14.5, (float) $campi['Altezza (m)']['dopo'], 0.001);

        // La prima compilazione: da vuoto ai primi valori
        $inizio = collect($storia[1]['modifiche'])->firstWhere('campo', 'Specie');
        $this->assertNull($inizio['prima']);
        $this->assertSame('Tilia cordata', $inizio['dopo']);
    }

    public function test_i_collegamenti_si_mostrano_con_il_nome_non_con_il_codice(): void
    {
        $id = $this->creaElemento();
        $altra = $this->createArea($this->organization, ['name' => 'Giardino Nord']);

        $this->patchJson("/api/v1/assets/{$id}", ['area_id' => $altra->id])->assertOk();

        $storia = $this->getJson("/api/v1/assets/{$id}/versioni")->assertOk()->json('data');

        $campi = collect($storia[0]['modifiche'])->keyBy('campo');
        $this->assertSame('Area Test', $campi['Area']['prima']);
        $this->assertSame('Giardino Nord', $campi['Area']['dopo']);
    }

    public function test_lo_spostamento_sulla_mappa_e_raccontato(): void
    {
        $id = $this->creaElemento();

        $this->patchJson("/api/v1/assets/{$id}", [
            'geometry' => $this->pointGeometry(9.2000, 45.4700),
        ])->assertOk();

        $storia = $this->getJson("/api/v1/assets/{$id}/versioni")->assertOk()->json('data');

        $this->assertCount(1, $storia);
        $mod = collect($storia[0]['modifiche'])->firstWhere('campo', 'Posizione o geometria');
        $this->assertNotNull($mod);
        $this->assertSame('modificata sulla mappa', $mod['dopo']);
    }

    public function test_una_data_rimasta_uguale_non_ricompare_come_finta_modifica(): void
    {
        $id = $this->creaElemento();

        $this->patchJson("/api/v1/assets/{$id}", ['surveyed_at' => '2026-08-18'])->assertOk();
        $this->patchJson("/api/v1/assets/{$id}", ['notes' => 'Aggiunta una nota'])->assertOk();

        $storia = $this->getJson("/api/v1/assets/{$id}/versioni")->assertOk()->json('data');

        // La fotografia jsonb porta "2026-08-18", la scheda attuale un oggetto
        // data: se il confronto non li riporta alla stessa forma, l'ultima
        // revisione mostrerebbe "Data di rilievo: 18/08/2026 -> 18/08/2026"
        $this->assertSame(['Note'], array_column($storia[0]['modifiche'], 'campo'));

        foreach ($storia as $revisione) {
            foreach ($revisione['modifiche'] as $m) {
                $this->assertNotSame($m['prima'], $m['dopo'],
                    "La storia mostra {$m['campo']} con lo stesso valore prima e dopo.");
            }
        }
    }

    public function test_scheda_appena_creata_non_ha_storia(): void
    {
        $id = $this->creaElemento();

        $this->getJson("/api/v1/assets/{$id}/versioni")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_la_storia_non_si_vede_da_un_altro_tenant(): void
    {
        $id = $this->creaElemento();
        $this->patchJson("/api/v1/assets/{$id}", ['notes' => 'Riservato'])->assertOk();

        [, $estraneo] = $this->createTenantUser();
        $this->actingAsTenantUser($estraneo);

        $this->getJson("/api/v1/assets/{$id}/versioni")->assertNotFound();
    }

    private function creaElemento(): string
    {
        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->pointType->id,
            'census_code' => 'ALB-0001',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }
}
