<?php

use App\Enums\BookingStatus;
use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);
});

function confirmedBooking(User $owner, ?Space $space = null): Booking
{
    $resource = Resource::factory()->for($space ?? Space::factory()->create())->create();
    $start = now()->addDay()->setTime(10, 0);

    return Booking::factory()->confirmed()
        ->for($owner, 'user')
        ->withSlot($resource, $start, $start->copy()->addHour())
        ->create();
}

test('viewAny and create depend only on the permission', function (RoleName $role, bool $viewAny, bool $create) {
    $user = userWithRole($role);

    expect($user->can('viewAny', Booking::class))->toBe($viewAny)
        ->and($user->can('create', Booking::class))->toBe($create);
})->with([
    'usuario' => [RoleName::Member, false, true],
    'recepción' => [RoleName::Reception, true, true],
    'responsable' => [RoleName::SpaceManager, true, false],
    'contable' => [RoleName::Accountant, false, false],
    'administrador' => [RoleName::Admin, true, true],
]);

test('applies each role to own, managed and unrelated bookings', function (
    string $ability,
    RoleName $role,
    bool $own,
    bool $managed,
    bool $unrelated,
) {
    $user = userWithRole($role);
    $managedSpace = Space::factory()->create(['manager_id' => $user->id]);

    expect($user->can($ability, confirmedBooking($user)))->toBe($own, 'own')
        ->and($user->can($ability, confirmedBooking(User::factory()->create(), $managedSpace)))->toBe($managed, 'managed')
        ->and($user->can($ability, confirmedBooking(User::factory()->create())))->toBe($unrelated, 'unrelated');
})->with([
    'view usuario' => ['view', RoleName::Member, true, false, false],
    'view recepción' => ['view', RoleName::Reception, true, true, true],
    'view responsable' => ['view', RoleName::SpaceManager, true, true, false],
    'view contable' => ['view', RoleName::Accountant, true, false, false],
    'view administrador' => ['view', RoleName::Admin, true, true, true],
    'cancel usuario' => ['cancel', RoleName::Member, true, false, false],
    'cancel recepción' => ['cancel', RoleName::Reception, true, true, true],
    'cancel responsable' => ['cancel', RoleName::SpaceManager, false, false, false],
    'cancel contable' => ['cancel', RoleName::Accountant, false, false, false],
    'cancel administrador' => ['cancel', RoleName::Admin, true, true, true],
]);

test('a booking with one managed resource among several counts as managed', function () {
    $manager = userWithRole(RoleName::SpaceManager);
    $managedResource = Resource::factory()->for(Space::factory()->create(['manager_id' => $manager->id]))->create();
    $otherResource = Resource::factory()->create();
    $start = now()->addDay()->setTime(10, 0);

    $booking = Booking::factory()->confirmed()
        ->withSlot($otherResource, $start, $start->copy()->addHour())
        ->withSlot($managedResource, $start, $start->copy()->addHour())
        ->create();

    expect($manager->can('view', $booking))->toBeTrue();
});

test('applies each role to a booking in a space run by another manager', function (string $ability, RoleName $role, bool $expected) {
    $user = userWithRole($role);
    $otherSpace = Space::factory()->create(['manager_id' => userWithRole(RoleName::SpaceManager)->id]);

    expect($user->can($ability, confirmedBooking(User::factory()->create(), $otherSpace)))->toBe($expected);
})->with([
    'view usuario' => ['view', RoleName::Member, false],
    'view recepción' => ['view', RoleName::Reception, true],
    'view responsable' => ['view', RoleName::SpaceManager, false],
    'view contable' => ['view', RoleName::Accountant, false],
    'view administrador' => ['view', RoleName::Admin, true],
    'cancel usuario' => ['cancel', RoleName::Member, false],
    'cancel recepción' => ['cancel', RoleName::Reception, true],
    'cancel responsable' => ['cancel', RoleName::SpaceManager, false],
    'cancel contable' => ['cancel', RoleName::Accountant, false],
    'cancel administrador' => ['cancel', RoleName::Admin, true],
]);

test('a user without a role cannot view even their own booking', function () {
    $user = User::factory()->create();

    expect($user->can('view', confirmedBooking($user)))->toBeFalse();
});

test('a guest can do nothing with bookings', function () {
    $booking = confirmedBooking(User::factory()->create());
    $guest = Gate::forUser(null);

    expect($guest->allows('viewAny', Booking::class))->toBeFalse()
        ->and($guest->allows('create', Booking::class))->toBeFalse()
        ->and($guest->allows('view', $booking))->toBeFalse()
        ->and($guest->allows('cancel', $booking))->toBeFalse();
});

test('cancel decides who, never when: it does not depend on the booking state', function (array $state) {
    $owner = userWithRole(RoleName::Member);
    $admin = userWithRole(RoleName::Admin);
    $other = userWithRole(RoleName::Member);
    $booking = Booking::factory()->for($owner, 'user')->create($state);

    expect($owner->can('cancel', $booking))->toBeTrue()
        ->and($admin->can('cancel', $booking))->toBeTrue()
        ->and($other->can('cancel', $booking))->toBeFalse();
})->with([
    'confirmada' => fn () => ['status' => BookingStatus::Confirmed, 'expires_at' => null],
    'completada' => fn () => ['status' => BookingStatus::Completed, 'expires_at' => null],
    'pendiente vigente' => fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => now()->addMinutes(5)],
    'pendiente vencida' => fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => now()->subMinute()],
    'cancelada' => fn () => ['status' => BookingStatus::Cancelled, 'expires_at' => null],
    'caducada' => fn () => ['status' => BookingStatus::Expired, 'expires_at' => now()->subMinute()],
]);
