<?php

use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\Resource;
use App\Models\Space;
use App\Models\User;
use App\Policies\IncidentPolicy;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);
});

function incidentOn(?Space $space = null, ?User $author = null): Incident
{
    return Incident::factory()
        ->for($author ?? User::factory()->create(), 'user')
        ->for(Resource::factory()->for($space ?? Space::factory()->create())->create(), 'resource')
        ->create();
}

test('the policy is registered', function () {
    expect(Gate::getPolicyFor(Incident::class))->toBeInstanceOf(IncidentPolicy::class);
});

test('viewAny and create depend only on the permission', function (RoleName $role, bool $viewAny, bool $create) {
    $user = userWithRole($role);

    expect($user->can('viewAny', Incident::class))->toBe($viewAny)
        ->and($user->can('create', Incident::class))->toBe($create);
})->with([
    'usuario' => [RoleName::Member, false, true],
    'recepción' => [RoleName::Reception, true, true],
    'responsable' => [RoleName::SpaceManager, true, true],
    'contable' => [RoleName::Accountant, false, false],
    'administrador' => [RoleName::Admin, true, true],
]);

test('applies each role to authored, managed and unrelated incidents', function (
    string $ability,
    RoleName $role,
    bool $authored,
    bool $managed,
    bool $unrelated,
) {
    $user = userWithRole($role);
    $managedSpace = Space::factory()->create(['manager_id' => $user->id]);

    expect($user->can($ability, incidentOn(author: $user)))->toBe($authored, 'authored')
        ->and($user->can($ability, incidentOn($managedSpace)))->toBe($managed, 'managed')
        ->and($user->can($ability, incidentOn()))->toBe($unrelated, 'unrelated');
})->with([
    'view usuario' => ['view', RoleName::Member, true, false, false],
    'view recepción' => ['view', RoleName::Reception, true, true, true],
    'view responsable' => ['view', RoleName::SpaceManager, true, true, false],
    'view contable' => ['view', RoleName::Accountant, false, false, false],
    'view administrador' => ['view', RoleName::Admin, true, true, true],
    'respond usuario' => ['respond', RoleName::Member, true, false, false],
    'respond recepción' => ['respond', RoleName::Reception, true, false, false],
    'respond responsable' => ['respond', RoleName::SpaceManager, true, true, false],
    'respond contable' => ['respond', RoleName::Accountant, false, false, false],
    'respond administrador' => ['respond', RoleName::Admin, true, true, true],
    'update usuario' => ['update', RoleName::Member, false, false, false],
    'update recepción' => ['update', RoleName::Reception, false, false, false],
    'update responsable' => ['update', RoleName::SpaceManager, false, true, false],
    'update contable' => ['update', RoleName::Accountant, false, false, false],
    'update administrador' => ['update', RoleName::Admin, true, true, true],
    'delete usuario' => ['delete', RoleName::Member, false, false, false],
    'delete recepción' => ['delete', RoleName::Reception, false, false, false],
    'delete responsable' => ['delete', RoleName::SpaceManager, false, false, false],
    'delete contable' => ['delete', RoleName::Accountant, false, false, false],
    'delete administrador' => ['delete', RoleName::Admin, false, false, false],
]);

test('reception responds to its own incident but not to one by another user or on a foreign resource', function () {
    $reception = userWithRole(RoleName::Reception);
    $own = incidentOn(author: $reception);
    $byAnotherUser = Incident::factory()->for(User::factory()->create(), 'user')->for($own->resource, 'resource')->create();
    $onForeignResource = incidentOn();

    expect($reception->can('respond', $own))->toBeTrue()
        ->and($reception->can('respond', $byAnotherUser))->toBeFalse()
        ->and($reception->can('respond', $onForeignResource))->toBeFalse();
});

test('only the manager of the space of the resource acts as its manager', function () {
    $manager = userWithRole(RoleName::SpaceManager);
    $otherManager = userWithRole(RoleName::SpaceManager);
    $incident = incidentOn(Space::factory()->create(['manager_id' => $manager->id]));

    foreach (['view', 'respond', 'update'] as $ability) {
        expect($manager->can($ability, $incident))->toBeTrue($ability)
            ->and($otherManager->can($ability, $incident))->toBeFalse($ability);
    }
});

test('being the manager of a space without the role gives nothing', function () {
    $member = userWithRole(RoleName::Member);
    $incident = incidentOn(Space::factory()->create(['manager_id' => $member->id]));

    expect($member->can('view', $incident))->toBeFalse()
        ->and($member->can('respond', $incident))->toBeFalse();
});

test('a guest can do nothing', function () {
    $incident = incidentOn();

    expect(Gate::forUser(null)->allows('viewAny', Incident::class))->toBeFalse()
        ->and(Gate::forUser(null)->allows('create', Incident::class))->toBeFalse();

    foreach (['view', 'respond', 'update', 'delete'] as $ability) {
        expect(Gate::forUser(null)->allows($ability, $incident))->toBeFalse($ability);
    }
});

test('update decides who, never when: a resolved incident gives the same answer', function () {
    $manager = userWithRole(RoleName::SpaceManager);
    $resource = Resource::factory()->for(Space::factory()->create(['manager_id' => $manager->id]))->create();
    $resolved = Incident::factory()->resolved()->for($resource, 'resource')->create();

    expect($manager->can('update', $resolved))->toBeTrue()
        ->and(userWithRole(RoleName::Admin)->can('update', $resolved))->toBeTrue()
        ->and($resolved->user->can('update', $resolved))->toBeFalse();
});

test('checking several abilities on the same incident repeats no query', function () {
    $manager = userWithRole(RoleName::SpaceManager);
    $incident = incidentOn(Space::factory()->create(['manager_id' => $manager->id]));
    $manager->can('view', $incident);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $manager->can('respond', $incident);
    $manager->can('update', $incident);

    expect(DB::getQueryLog())->toBe([]);
});
