<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles y permisos del proyecto; la matriz está explicada en docs/permisos.md.
 * Se puede ejecutar varias veces sin duplicar nada.
 */
class RoleSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'spaces.view-any', 'spaces.create', 'spaces.update', 'spaces.delete',
        'resources.view-any', 'resources.create', 'resources.update', 'resources.delete',
        'bookings.view-any', 'bookings.view', 'bookings.create', 'bookings.cancel',
        'incidents.view-any', 'incidents.view', 'incidents.create', 'incidents.update', 'incidents.respond',
        'invoices.view-any', 'invoices.view', 'invoices.create', 'invoices.void',
        'payments.view-any', 'payments.view', 'payments.create',
        'tariffs.view-any', 'tariffs.manage',
        'users.view-any', 'users.manage',
        'reports.view',
    ];

    /**
     * Permisos de cada rol. El administrador no aparece porque los recibe todos.
     */
    private const array ROLE_PERMISSIONS = [
        RoleName::Member->value => [
            'bookings.view', 'bookings.create', 'bookings.cancel',
            'incidents.view', 'incidents.create', 'incidents.respond',
            'invoices.view',
            'payments.view', 'payments.create',
            'tariffs.view-any',
        ],
        RoleName::Reception->value => [
            'spaces.view-any',
            'resources.view-any',
            'bookings.view-any', 'bookings.view', 'bookings.create', 'bookings.cancel',
            'incidents.view-any', 'incidents.view', 'incidents.create',
            'tariffs.view-any',
            'users.view-any',
        ],
        RoleName::SpaceManager->value => [
            'spaces.view-any', 'spaces.update',
            'resources.view-any', 'resources.create', 'resources.update',
            'bookings.view-any', 'bookings.view',
            'incidents.view-any', 'incidents.view', 'incidents.create', 'incidents.update', 'incidents.respond',
            'tariffs.view-any',
            'reports.view',
        ],
        RoleName::Accountant->value => [
            'bookings.view',
            'invoices.view-any', 'invoices.view', 'invoices.create', 'invoices.void',
            'payments.view-any', 'payments.view',
            'tariffs.view-any',
            'reports.view',
        ],
    ];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Con WithoutModelEvents (DatabaseSeeder) Spatie no refresca solo esta caché.
        $registrar->forgetCachedPermissions();

        foreach (self::ROLE_PERMISSIONS as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        Role::findOrCreate(RoleName::Admin->value, 'web')->syncPermissions(Permission::all());

        $registrar->forgetCachedPermissions();
    }
}
