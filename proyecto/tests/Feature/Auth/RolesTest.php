<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

describe('role seeder', function () {
    test('creates the five roles with their exact names', function () {
        $this->seed(RoleSeeder::class);

        expect(Role::pluck('name')->sort()->values()->all())->toBe([
            'administrador',
            'contable',
            'recepción',
            'responsable de espacio',
            'usuario',
        ]);
    });

    test('creates every permission', function () {
        $this->seed(RoleSeeder::class);

        expect(Permission::count())->toBe(count(RoleSeeder::PERMISSIONS));
    });

    test('can run several times without duplicating anything', function () {
        $this->seed(RoleSeeder::class);

        $links = DB::table('role_has_permissions')->count();

        $this->seed(RoleSeeder::class);

        expect(Role::count())->toBe(5)
            ->and(Permission::count())->toBe(count(RoleSeeder::PERMISSIONS))
            ->and(DB::table('role_has_permissions')->count())->toBe($links);
    });

    test('gives every permission to the administrator', function () {
        $this->seed(RoleSeeder::class);

        expect(Role::findByName(RoleName::Admin->value)->permissions()->count())
            ->toBe(count(RoleSeeder::PERMISSIONS));
    });

    test('applies the approved matrix', function (RoleName $role, array $allowed, array $denied) {
        $this->seed(RoleSeeder::class);

        $user = userWithRole($role);

        foreach ($allowed as $permission) {
            expect($user->can($permission))->toBeTrue("{$role->value} should have {$permission}");
        }

        foreach ($denied as $permission) {
            expect($user->can($permission))->toBeFalse("{$role->value} should not have {$permission}");
        }
    })->with([
        'usuario' => [
            RoleName::Member,
            ['bookings.create', 'payments.create', 'incidents.respond'],
            ['bookings.view-any', 'incidents.update', 'tariffs.manage', 'users.manage'],
        ],
        'recepción' => [
            RoleName::Reception,
            ['bookings.cancel', 'users.view-any', 'spaces.view-any'],
            ['incidents.respond', 'incidents.update', 'payments.create', 'users.manage'],
        ],
        'responsable de espacio' => [
            RoleName::SpaceManager,
            ['resources.create', 'incidents.update', 'incidents.respond', 'reports.view'],
            ['resources.delete', 'bookings.cancel', 'tariffs.manage'],
        ],
        'contable' => [
            RoleName::Accountant,
            ['invoices.void', 'payments.view-any', 'reports.view', 'bookings.view'],
            ['payments.create', 'bookings.create', 'tariffs.manage'],
        ],
    ]);
});

describe('demo users', function () {
    test('have the role that matches their email', function (string $email, RoleName $role) {
        $this->seed();

        $user = User::where('email', $email)->firstOrFail();

        expect($user->getRoleNames()->all())->toBe([$role->value]);
    })->with([
        ['admin@workspace.test', RoleName::Admin],
        ['contable@workspace.test', RoleName::Accountant],
        ['recepcion@workspace.test', RoleName::Reception],
        ['responsable1@workspace.test', RoleName::SpaceManager],
        ['responsable2@workspace.test', RoleName::SpaceManager],
        ['usuario1@workspace.test', RoleName::Member],
        ['usuario2@workspace.test', RoleName::Member],
        ['usuario3@workspace.test', RoleName::Member],
        ['usuario4@workspace.test', RoleName::Member],
        ['usuario5@workspace.test', RoleName::Member],
    ]);
});

describe('routes by role', function () {
    beforeEach(function () {
        $this->seed(RoleSeeder::class);
    });

    dataset('management routes', [
        'admin' => ['admin.index', RoleName::Admin],
        'accounting' => ['accounting.index', RoleName::Accountant],
        'reception' => ['reception.index', RoleName::Reception],
        'manager' => ['manager.index', RoleName::SpaceManager],
    ]);

    test('lets its role in', function (string $routeName, RoleName $role) {
        $this->actingAs(userWithRole($role))
            ->get(route($routeName))
            ->assertOk();
    })->with('management routes');

    test('returns 403 to every other role', function (string $routeName, RoleName $role) {
        $otherRoles = array_filter(RoleName::cases(), fn (RoleName $other) => $other !== $role);

        foreach ($otherRoles as $otherRole) {
            $this->actingAs(userWithRole($otherRole))
                ->get(route($routeName))
                ->assertForbidden();
        }
    })->with('management routes');

    test('returns 403 to a user without roles', function (string $routeName) {
        $this->actingAs(User::factory()->create())
            ->get(route($routeName))
            ->assertForbidden();
    })->with(['admin.index', 'accounting.index', 'reception.index', 'manager.index']);

    test('redirects a guest to the login', function (string $routeName) {
        $this->get(route($routeName))
            ->assertRedirect(route('login'));
    })->with(['admin.index', 'accounting.index', 'reception.index', 'manager.index']);

    test('keeps the dashboard open to every role', function (RoleName $role) {
        $this->actingAs(userWithRole($role))
            ->get(route('dashboard'))
            ->assertOk();
    })->with(RoleName::cases());
});
