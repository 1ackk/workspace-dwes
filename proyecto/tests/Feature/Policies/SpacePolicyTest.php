<?php

use App\Enums\RoleName;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);
});

test('the policy is registered', function () {
    expect(Gate::getPolicyFor(Space::class))->toBeInstanceOf(SpacePolicy::class);
});

test('the catalogue is public for every role', function (RoleName $role) {
    $user = userWithRole($role);

    expect($user->can('viewAny', Space::class))->toBeTrue()
        ->and($user->can('view', Space::factory()->create()))->toBeTrue();
})->with(RoleName::cases());

test('a guest can read the catalogue and nothing else', function () {
    $space = Space::factory()->create();
    $guest = Gate::forUser(null);

    expect($guest->allows('viewAny', Space::class))->toBeTrue()
        ->and($guest->allows('view', $space))->toBeTrue()
        ->and($guest->allows('viewManagement', Space::class))->toBeFalse()
        ->and($guest->allows('create', Space::class))->toBeFalse()
        ->and($guest->allows('update', $space))->toBeFalse()
        ->and($guest->allows('delete', $space))->toBeFalse();
});

test('viewManagement and create depend only on the permission', function (RoleName $role, bool $viewManagement, bool $create) {
    $user = userWithRole($role);

    expect($user->can('viewManagement', Space::class))->toBe($viewManagement)
        ->and($user->can('create', Space::class))->toBe($create);
})->with([
    'usuario' => [RoleName::Member, false, false],
    'recepción' => [RoleName::Reception, true, false],
    'responsable' => [RoleName::SpaceManager, true, false],
    'contable' => [RoleName::Accountant, false, false],
    'administrador' => [RoleName::Admin, true, true],
]);

test('applies each role to managed and unrelated spaces', function (string $ability, RoleName $role, bool $managed, bool $unrelated) {
    $user = userWithRole($role);

    expect($user->can($ability, Space::factory()->create(['manager_id' => $user->id])))->toBe($managed, 'managed')
        ->and($user->can($ability, Space::factory()->create()))->toBe($unrelated, 'unrelated');
})->with([
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

test('the manager of another space cannot touch this one', function () {
    $manager = userWithRole(RoleName::SpaceManager);
    $otherManager = userWithRole(RoleName::SpaceManager);
    $space = Space::factory()->create(['manager_id' => $manager->id]);

    expect($manager->can('update', $space))->toBeTrue()
        ->and($otherManager->can('update', $space))->toBeFalse()
        ->and(Gate::forUser($otherManager)->denies('update', $space))->toBeTrue();
});
