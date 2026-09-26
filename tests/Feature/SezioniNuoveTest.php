<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Le sezioni della veste nuova che riuniscono pagine di prima (blocchi 5, 6 e
 * 7): Documenti in un elenco solo, Committenti con i loro numeri,
 * Impostazioni come casa delle regolazioni. Ogni sorgente esce solo a chi ha
 * il permesso della sua pagina.
 */
class SezioniNuoveTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->actingAsTenantUser($this->utente);
    }

    public function test_le_pagine_si_aprono_secondo_i_permessi(): void
    {
        $this->get('/documenti')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Nuovo/Documenti'));
        $this->get('/committenti')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Nuovo/Committenti'));
        $this->get('/impostazioni')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Nuovo/Impostazioni')->has('dominioPortali'));

        [, $cliente] = $this->createTenantUser([], 'cliente');
        $this->actingAsTenantUser($cliente);
        $this->get('/documenti')->assertForbidden();
        $this->get('/committenti')->assertForbidden();
        $this->getJson('/api/v1/documenti')->assertForbidden();
        $this->getJson('/api/v1/committenti/riepilogo')->assertForbidden();
        // Le impostazioni si aprono a tutti: c'e' almeno la scelta dell'interfaccia
        $this->get('/impostazioni')->assertOk();
    }

    public function test_i_documenti_escono_in_un_elenco_solo_con_conteggi_e_filtri(): void
    {
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $asset = $this->postJson('/api/v1/assets', ['area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry()])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/assets/{$asset}", ['tree' => ['species' => 'Tilia cordata']])->assertOk();
        $valutazione = $this->postJson("/api/v1/assets/{$asset}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => '2026-03-10', 'failure_class' => 'B', 'next_check_due' => '2027-03-10',
        ])->assertCreated()->json('data');
        // Emessa (numerata) ma non validata
        DB::table('tree_assessments')->where('id', $valutazione['id'])->update(['report_number' => 'PER-2026-0007', 'report_issued_at' => now()]);
        // Una valutazione senza rapporto emesso non e' un documento
        $this->postJson("/api/v1/assets/{$asset}/assessments", ['assessment_type' => 'vta_visual', 'assessed_on' => '2026-04-10', 'failure_class' => 'B'])->assertCreated();

        $cliente = Client::withoutGlobalScopes()->where('tenant_id', $this->organizzazione->id)->firstOrFail();
        DB::table('estimates')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->organizzazione->id, 'code' => 'PRV-2026-0001', 'client_id' => $cliente->id,
            'title' => 'Potatura del viale', 'status' => 'accepted', 'vat_percent' => 22, 'version' => 1,
            'created_by' => $this->utente->id, 'updated_by' => $this->utente->id, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->organizzazione->id, 'user_id' => $this->utente->id,
            'action' => 'export.cam_delivery', 'payload' => json_encode(['format' => 'shapefile', 'riferimento' => 'CAM-2026-01']),
            'created_at' => now()->subDays(2),
        ]);

        $risposta = $this->getJson('/api/v1/documenti')->assertOk()->json();
        $righe = collect($risposta['data']);

        $this->assertSame(['perizia', 'preventivo', 'esportazione'], $righe->pluck('tipo')->all());
        $this->assertSame(['tutti' => 3, 'da_validare' => 1, 'perizia' => 1, 'verbale' => 0, 'preventivo' => 1, 'sal' => 0, 'esportazione' => 1], $risposta['conteggi']);
        $this->assertSame([now()->year], $risposta['anni']);

        $perizia = $righe->firstWhere('tipo', 'perizia');
        $this->assertStringContainsString('PER-2026-0007', $perizia['titolo']);
        $this->assertStringContainsString('Tilia cordata', $perizia['titolo']);
        $this->assertSame('Da validare', $perizia['stato']);
        $this->assertSame("/censimento/{$asset}?vta=1", $perizia['href']);
        $this->assertSame('Cliente Test', $perizia['committente']);

        $esportazione = $righe->firstWhere('tipo', 'esportazione');
        $this->assertSame('Consegna CAM completa · shapefile · riferimento CAM-2026-01', $esportazione['titolo']);
        $this->assertSame($this->utente->name, $esportazione['utente']);

        $this->assertCount(1, $this->getJson('/api/v1/documenti?tipo=perizia')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/documenti?tipo=preventivo,sal,esportazione')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/documenti?stato=da_validare')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/documenti?q=tilia')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/documenti?q=potatura viale')->json('data'));
        $this->assertCount(2, $this->getJson("/api/v1/documenti?client_id={$cliente->id}")->json('data'));

        // Chi vede solo i lavori non vede perizie ed esportazioni
        $this->postJson('/api/v1/roles', ['nome' => 'Solo lavori', 'permessi' => ['works.view']])->assertCreated();
        $capo = User::factory()->create(['tenant_id' => $this->organizzazione->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organizzazione->id);
        $capo->assignRole('Solo lavori');
        $this->actingAsTenantUser($capo->fresh());
        $this->assertSame(['preventivo'], collect($this->getJson('/api/v1/documenti')->assertOk()->json('data'))->pluck('tipo')->all());
    }

    public function test_il_riepilogo_dei_committenti_porta_i_numeri_che_servono(): void
    {
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        foreach ([1, 2] as $i) {
            $this->postJson('/api/v1/assets', ['area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry()])->assertCreated();
        }
        $cliente = Client::withoutGlobalScopes()->where('tenant_id', $this->organizzazione->id)->firstOrFail();
        $cliente->forceFill(['public_enabled' => true, 'public_slug' => 'cliente-test', 'public_profile' => ['contact_email' => 'verde@test.it', 'show_co2' => true]])->save();
        WorkOrder::create([
            'tenant_id' => $this->organizzazione->id, 'code' => WorkOrder::nextCode($this->organizzazione->id), 'title' => 'Aperto',
            'status' => 'planned', 'client_id' => $cliente->id, 'area_id' => $this->area->id, 'created_by' => $this->utente->id, 'updated_by' => $this->utente->id,
        ]);
        WorkOrder::create([
            'tenant_id' => $this->organizzazione->id, 'code' => WorkOrder::nextCode($this->organizzazione->id), 'title' => 'Chiuso',
            'status' => 'completed', 'client_id' => $cliente->id, 'area_id' => $this->area->id, 'created_by' => $this->utente->id, 'updated_by' => $this->utente->id,
        ]);

        $righe = $this->getJson('/api/v1/committenti/riepilogo')->assertOk()->json('data');
        $this->assertCount(1, $righe);
        $r = $righe[0];
        $this->assertSame('Cliente Test', $r['nome']);
        $this->assertSame('Privato', $r['tipo_etichetta']);
        $this->assertSame(2, $r['elementi']);
        $this->assertSame(1, $r['aree']);
        $this->assertSame(1, $r['lavori_aperti']);
        $this->assertTrue($r['portale']['acceso']);
        $this->assertSame('cliente-test', $r['portale']['slug']);
        $this->assertSame(1, $r['portale']['recapiti']);
        $this->assertTrue($r['portale']['co2']);
    }
}
