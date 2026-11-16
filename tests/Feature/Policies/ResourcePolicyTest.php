<?php

use App\Enums\RoleName;
use App\Models\Resource;
use App\Models\Space;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);
});

test('viewAny and opening the create form depend only on the permission', function (RoleName $role, bool $viewAny, bool $create) {
    $user = userWithRole($role);

    expect($user->can('viewAny', Resource::class))->toBe($viewAny)
        ->and($user->can('create', Resource::class))->toBe($create);
})->with([
    'usuario' => [RoleName::Member, false, false],
    'recepción' => [RoleName::Reception, true, false],
    'responsable' => [RoleName::SpaceManager, true, true],
    'contable' => [RoleName::Accountant, false, false],
    'administrador' => [RoleName::Admin, true, true],
]);

test('applies each role to managed and unrelated resources', function (
    string $ability,
    RoleName $role,
    bool $managed,
    bool $unrelated,
) {
    $user = userWithRole($role);
    $managedResource = Resource::factory()->for(Space::factory()->create(['manager_id' => $user->id]))->create();
    $unrelatedResource = Resource::factory()->create();

    expect($user->can($ability, $managedResource))->toBe($managed, 'managed')
        ->and($user->can($ability, $unrelatedResource))->toBe($unrelated, 'unrelated');
})->with([
    'view usuario' => ['view', RoleName::Member, false, false],
    'view recepción' => ['view', RoleName::Reception, true, true],
    'view responsable' => ['view', RoleName::SpaceManager, true, false],
    'view contable' => ['view', RoleName::Accountant, false, false],
    'view administrador' => ['view', RoleName::Admin, true, true],
    'update usuario' => ['update', RoleName::Member, false, false],
    'update recepción' => ['update', RoleName::Reception, false, false],
    'update responsable' => ['update', RoleName::SpaceManager, true, false],
    'update contable' => ['update', RoleName::Accountant, false, false],
    'update administrador' => ['update', RoleName::Admin, true, true],
    'delete usuario' => ['delete', RoleName::Member, false, false],
    'delete recepción' => ['delete', RoleName::Reception, false, false],
    'delete responsable' => ['delete', RoleName::SpaceManager, false, false],
    'delete contable' => ['delete', RoleName::Accountant, false, false],
    'delete administrador' => ['delete', RoleName::Admin, true, true],
]);

test('applies each role to a resource in a space run by another manager', function (string $ability, RoleName $role, bool $expected) {
    $user = userWithRole($role);
    $otherSpace = Space::factory()->create(['manager_id' => userWithRole(RoleName::SpaceManager)->id]);
    $resource = Resource::factory()->for($otherSpace)->create();

    expect($user->can($ability, $resource))->toBe($expected);
})->with([
    'view usuario' => ['view', RoleName::Member, false],
    'view recepción' => ['view', RoleName::Reception, true],
    'view responsable' => ['view', RoleName::SpaceManager, false],
    'view contable' => ['view', RoleName::Accountant, false],
    'view administrador' => ['view', RoleName::Admin, true],
    'update usuario' => ['update', RoleName::Member, false],
    'update recepción' => ['update', RoleName::Reception, false],
    'update responsable' => ['update', RoleName::SpaceManager, false],
    'update contable' => ['update', RoleName::Accountant, false],
    'update administrador' => ['update', RoleName::Admin, true],
    'delete usuario' => ['delete', RoleName::Member, false],
    'delete recepción' => ['delete', RoleName::Reception, false],
    'delete responsable' => ['delete', RoleName::SpaceManager, false],
    'delete contable' => ['delete', RoleName::Accountant, false],
    'delete administrador' => ['delete', RoleName::Admin, true],
]);

test('a guest can do nothing with resources through the policy', function () {
    $resource = Resource::factory()->create();
    $guest = Gate::forUser(null);

    expect($guest->allows('viewAny', Resource::class))->toBeFalse()
        ->and($guest->allows('create', Resource::class))->toBeFalse()
        ->and($guest->allows('view', $resource))->toBeFalse()
        ->and($guest->allows('update', $resource))->toBeFalse()
        ->and($guest->allows('delete', $resource))->toBeFalse();
});

test('create in a space depends on who manages it', function (RoleName $role, bool $managed, bool $unrelated) {
    $user = userWithRole($role);
    $managedSpace = Space::factory()->create(['manager_id' => $user->id]);
    $unrelatedSpace = Space::factory()->create();

    expect($user->can('create', [Resource::class, $managedSpace]))->toBe($managed, 'managed')
        ->and($user->can('create', [Resource::class, $unrelatedSpace]))->toBe($unrelated, 'unrelated');
})->with([
    'usuario' => [RoleName::Member, false, false],
    'recepción' => [RoleName::Reception, false, false],
    'responsable' => [RoleName::SpaceManager, true, false],
    'contable' => [RoleName::Accountant, false, false],
    'administrador' => [RoleName::Admin, true, true],
]);
