<?php

use App\Enums\BookingStatus;
use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use App\Models\User;
use Database\Seeders\RoleSeeder;
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

test('cancel depends on the booking state', function (array $state, bool $expected) {
    $owner = userWithRole(RoleName::Member);
    $admin = userWithRole(RoleName::Admin);
    $booking = Booking::factory()->for($owner, 'user')->create($state);

    expect($owner->can('cancel', $booking))->toBe($expected)
        ->and($admin->can('cancel', $booking))->toBe($expected);
})->with([
    'confirmada' => fn () => [['status' => BookingStatus::Confirmed, 'expires_at' => null], true],
    'completada' => fn () => [['status' => BookingStatus::Completed, 'expires_at' => null], true],
    'pendiente vigente' => fn () => [['status' => BookingStatus::PendingPayment, 'expires_at' => now()->addMinutes(5)], true],
    'pendiente vencida' => fn () => [['status' => BookingStatus::PendingPayment, 'expires_at' => now()->subMinute()], false],
    'cancelada' => fn () => [['status' => BookingStatus::Cancelled, 'expires_at' => null], false],
    'caducada' => fn () => [['status' => BookingStatus::Expired, 'expires_at' => now()->subMinute()], false],
]);
