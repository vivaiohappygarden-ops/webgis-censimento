<?php

namespace Tests\Feature;

use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Che cosa si fa su ogni elemento di un ordine (punto 10 del committente,
 * 04/10/2026): siepe A "potatura e concimazione", siepe B "potatura e
 * ritentore idrico". La lavorazione e le note si scrivono per riga, lo
 * stesso elemento puo' avere piu' righe con lavorazioni diverse, l'app di
 * campo le riceve.
 */
class LavorazioniPerElementoTest extends TestCase
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

    private function siepe(string $codice, float $x): string
    {
        $tipo = \App\Models\CatalogObjectType::query()->where('code', 'P103201')->first() ?? $this->makeObjectType($this->organizzazione, 'L', 'P103201');

        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'census_code' => $codice,
            'geometry' => ['type' => 'LineString', 'coordinates' => [[9.1903 + $x, 45.4650], [9.1909 + $x, 45.4650]]],
        ])->assertCreated()->json('data.id');
    }

    public function test_ogni_riga_dice_che_cosa_si_fa_e_lo_stesso_elemento_puo_avere_piu_lavorazioni(): void
    {
        $potatura = WorkType::create(['tenant_id' => $this->organizzazione->id, 'code' => 'POT-S', 'name' => 'Potatura siepi', 'category' => 'verde', 'unit' => 'm', 'applicable_geometry' => 'L']);
        $concimazione = WorkType::create(['tenant_id' => $this->organizzazione->id, 'code' => 'CONC', 'name' => 'Concimazione', 'category' => 'verde', 'unit' => 'cad', 'applicable_geometry' => 'L']);
        $photinia = $this->siepe('SIE-0001', 0);
        $ligustro = $this->siepe('SIE-0002', 0.001);

        $ordine = $this->postJson('/api/v1/work-orders', ['title' => 'Siepi del parco', 'asset_ids' => [$photinia, $ligustro]])->assertCreated()->json('data');
        $righe = collect($ordine['assets']);
        $rigaPhotinia = $righe->firstWhere('asset_id', $photinia);

        // Sulla photinia: potatura, con la nota per la squadra
        $this->patchJson("/api/v1/work-orders/{$ordine['id']}/assets/{$rigaPhotinia['id']}", [
            'work_type_id' => $potatura->id, 'notes' => 'Potare a 1,20 m e concimare dopo il taglio', 'version' => $ordine['version'],
        ])->assertOk();
        // ...e una seconda riga per la concimazione
        $dopo = $this->postJson("/api/v1/work-orders/{$ordine['id']}/assets", ['asset_id' => $photinia, 'work_type_id' => $concimazione->id, 'notes' => 'Concime a lenta cessione'])->assertOk()->json('data');
        $righePhotinia = collect($dopo['assets'])->where('asset_id', $photinia)->values();
        $this->assertCount(2, $righePhotinia);
        $this->assertSame(['Potatura siepi', 'Concimazione'], $righePhotinia->pluck('work_type.name')->all());
        $this->assertSame('Potare a 1,20 m e concimare dopo il taglio', $righePhotinia[0]['notes']);

        // La stessa lavorazione due volte sullo stesso elemento no
        $this->postJson("/api/v1/work-orders/{$ordine['id']}/assets", ['asset_id' => $photinia, 'work_type_id' => $concimazione->id])->assertStatus(422);
        $this->patchJson("/api/v1/work-orders/{$ordine['id']}/assets/{$righePhotinia[1]['id']}", ['work_type_id' => $potatura->id, 'version' => $dopo['version']])
            ->assertStatus(422)->assertJsonValidationErrors(['work_type_id']);

        // Sul ligustro si toglie la lavorazione di riga (vale quella dell'ordine) e si scrive la nota
        $rigaLigustro = $righe->firstWhere('asset_id', $ligustro);
        $this->patchJson("/api/v1/work-orders/{$ordine['id']}/assets/{$rigaLigustro['id']}", ['work_type_id' => null, 'notes' => 'Potare e inserire ritentore idrico', 'version' => $dopo['version']])->assertOk();
        $finale = collect($this->getJson("/api/v1/work-orders/{$ordine['id']}")->assertOk()->json('data.assets'));
        $this->assertNull($finale->firstWhere('asset_id', $ligustro)['work_type_id']);
        $this->assertSame('Potare e inserire ritentore idrico', $finale->firstWhere('asset_id', $ligustro)['notes']);

        // L'app di campo riceve lavorazione e note per riga
        $this->patchJson("/api/v1/work-orders/{$ordine['id']}", ['assigned_to' => $this->utente->id, 'version' => $finale->isNotEmpty() ? $this->getJson("/api/v1/work-orders/{$ordine['id']}")->json('data.version') : 1])->assertOk();
        $this->postJson("/api/v1/work-orders/{$ordine['id']}/transition", ['status' => 'planned'])->assertOk();
        $this->postJson("/api/v1/work-orders/{$ordine['id']}/transition", ['status' => 'assigned'])->assertOk();
        $campo = collect($this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('work_orders'))->firstWhere('id', $ordine['id']);
        $this->assertNotNull($campo, "l'ordine assegnato arriva in campo");
        $note = collect($campo['assets'])->pluck('notes')->filter()->all();
        $this->assertContains('Potare e inserire ritentore idrico', $note);
        $this->assertContains('Concimazione', collect($campo['assets'])->pluck('work_type.name')->all());
    }

    public function test_la_lavorazione_di_riga_deve_esistere(): void
    {
        $siepe = $this->siepe('SIE-0003', 0);
        $ordine = $this->postJson('/api/v1/work-orders', ['title' => 'Siepi', 'asset_ids' => [$siepe]])->assertCreated()->json('data');
        $riga = $ordine['assets'][0];
        $this->patchJson("/api/v1/work-orders/{$ordine['id']}/assets/{$riga['id']}", ['work_type_id' => '00000000-0000-0000-0000-000000000001', 'version' => $ordine['version']])
            ->assertNotFound();
    }
}
