<?php

namespace App\Services\Tenancy;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea i permessi globali (idempotente) e i ruoli base di una nuova organizzazione.
 * I ruoli sono per-tenant e personalizzabili in seguito dall'amministratore.
 */
class TenantProvisioner
{
    public const PERMISSIONS = [
        'catalog.view', 'catalog.manage',
        'areas.view', 'areas.create', 'areas.update', 'areas.delete',
        'assets.view', 'assets.create', 'assets.update', 'assets.delete',
        'clients.view', 'clients.manage',
        'works.view', 'works.manage',
        'users.manage',
    ];

    public const ROLES = [
        'amministratore' => self::PERMISSIONS,
        'tecnico' => [
            'catalog.view', 'areas.view', 'areas.create', 'areas.update',
            'assets.view', 'assets.create', 'assets.update', 'assets.delete', 'clients.view',
            'works.view', 'works.manage',
        ],
        'operatore' => ['catalog.view', 'areas.view', 'assets.view', 'assets.create', 'assets.update', 'works.view'],
        'cliente' => ['areas.view', 'assets.view'],
    ];

    public function ensurePermissions(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    public function provisionRoles(Organization $organization): void
    {
        $this->ensurePermissions();

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($organization->id);

        try {
            foreach (self::ROLES as $roleName => $permissions) {
                /** @var Role $role */
                $role = Role::firstOrCreate([
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'tenant_id' => $organization->id,
                ]);
                $role->syncPermissions($permissions);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }
}
