<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CustomFieldsTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    private $type;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->area = $this->createArea($this->organization);
        $this->type = $this->makeObjectType($this->organization, 'P');
        $this->actingAsTenantUser($this->user);
    }

    private function defineFields(): void
    {
        $this->postJson('/api/v1/custom-fields', [
            'object_type_id' => $this->type->id,
            'key' => 'stato_salute',
            'label' => 'Stato di salute',
            'field_type' => 'select',
            'required' => true,
            'options' => ['buono', 'medio', 'scarso'],
        ])->assertCreated();

        $this->postJson('/api/v1/custom-fields', [
            'object_type_id' => $this->type->id,
            'key' => 'dbh_cm',
            'label' => 'Diametro fusto',
            'field_type' => 'integer',
            'validation' => ['min' => 1, 'max' => 500],
            'unit' => 'cm',
        ])->assertCreated();
    }

    public function test_attributes_are_validated_against_custom_fields(): void
    {
        $this->defineFields();

        // Valido
        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
            'attributes' => ['stato_salute' => 'buono', 'dbh_cm' => 42],
        ])->assertCreated()->assertJsonPath('data.attributes.dbh_cm', 42);

        // Campo obbligatorio mancante
        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(9.192, 45.466),
            'attributes' => ['dbh_cm' => 42],
        ])->assertUnprocessable()->assertJsonValidationErrors('attributes.stato_salute');

        // Valore fuori dalla lista
        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(9.193, 45.466),
            'attributes' => ['stato_salute' => 'ottimo'],
        ])->assertUnprocessable()->assertJsonValidationErrors('attributes.stato_salute');

        // Fuori range numerico
        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(9.194, 45.466),
            'attributes' => ['stato_salute' => 'buono', 'dbh_cm' => 9999],
        ])->assertUnprocessable()->assertJsonValidationErrors('attributes.dbh_cm');

        // Chiave non prevista
        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(9.195, 45.466),
            'attributes' => ['stato_salute' => 'buono', 'campo_inventato' => 'x'],
        ])->assertUnprocessable()->assertJsonValidationErrors('attributes');
    }

    public function test_duplicate_field_key_is_rejected(): void
    {
        $this->defineFields();

        $this->postJson('/api/v1/custom-fields', [
            'object_type_id' => $this->type->id,
            'key' => 'stato_salute',
            'label' => 'Doppione',
            'field_type' => 'text',
        ])->assertUnprocessable()->assertJsonValidationErrors('key');
    }

    public function test_operatore_cannot_manage_catalog_fields(): void
    {
        [$org2, $operatore] = [$this->organization, null];
        $operatore = \App\Models\User::factory()->create(['tenant_id' => $org2->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($org2->id);
        $operatore->assignRole('operatore');
        $this->actingAsTenantUser($operatore);

        $this->postJson('/api/v1/custom-fields', [
            'object_type_id' => $this->type->id,
            'key' => 'abusivo',
            'label' => 'Abusivo',
            'field_type' => 'text',
        ])->assertForbidden();
    }
}
