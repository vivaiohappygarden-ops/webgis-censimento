<?php

use App\Models\Organization;
use App\Models\Role;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * I permessi del modulo Lavori (works.view/works.manage) esistono solo per i
 * tenant creati DOPO l'introduzione del modulo: qui si aggiungono ai ruoli
 * base dei tenant già provisionati. Additivo (givePermissionTo), mai
 * syncPermissions: i ruoli personalizzati dagli amministratori non si toccano.
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'amministratore' => ['works.view', 'works.manage'],
        'tecnico' => ['works.view', 'works.manage'],
        'operatore' => ['works.view'],
    ];

    public function up(): void
    {
        app(TenantProvisioner::class)->ensurePermissions();

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            foreach (Organization::query()->pluck('id') as $tenantId) {
                $registrar->setPermissionsTeamId($tenantId);

                foreach (self::NEW_PERMISSIONS as $roleName => $permissions) {
                    $role = Role::query()
                        ->where('tenant_id', $tenantId)
                        ->where('name', $roleName)
                        ->first();
                    $role?->givePermissionTo($permissions);
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // I permessi restano: rimuoverli potrebbe togliere accessi assegnati a mano
    }
};
