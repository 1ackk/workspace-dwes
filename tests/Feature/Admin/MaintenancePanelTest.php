<?php

use App\Enums\BookingStatus;
use App\Enums\RoleName;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Models\Booking;
use App\Models\Resource;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);
});

test('the admin page offers both tasks with a protected form', function () {
    $this->actingAs(userWithRole(RoleName::Admin))
        ->get(route('admin.index'))
        ->assertOk()
        ->assertSee(route('admin.maintenance.run', 'release-unpaid'))
        ->assertSee(route('admin.maintenance.run', 'complete-past'))
        ->assertSee('name="_token"', false);
});

test('the admin releases unpaid bookings and sees the result', function () {
    $booking = Booking::factory()->create(['expires_at' => '2026-12-01 08:00:00']);

    $this->actingAs(userWithRole(RoleName::Admin))
        ->followingRedirects()
        ->post(route('admin.maintenance.run', 'release-unpaid'))
        ->assertOk()
        ->assertSee('Reservas liberadas: 1.');

    expect($booking->fresh()->status)->toBe(BookingStatus::Expired);
});

test('the admin completes finished bookings and sees the result', function () {
    $booking = Booking::factory()->confirmed()
        ->withSlot(Resource::factory()->create(), Carbon::parse('2026-11-30 10:00'), Carbon::parse('2026-11-30 11:00'))
        ->create();

    $this->actingAs(userWithRole(RoleName::Admin))
        ->followingRedirects()
        ->post(route('admin.maintenance.run', 'complete-past'))
        ->assertOk()
        ->assertSee('Reservas completadas: 1.');

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
});

test('every other role receives a 403 and nothing runs', function (RoleName $role, string $task) {
    $booking = Booking::factory()->create(['expires_at' => '2026-12-01 08:00:00']);

    $this->actingAs(userWithRole($role))
        ->post(route('admin.maintenance.run', $task))
        ->assertForbidden();

    expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
})->with([RoleName::Member, RoleName::Reception, RoleName::SpaceManager, RoleName::Accountant])
    ->with(['release-unpaid', 'complete-past']);

test('a guest goes to the login', function (string $task) {
    $this->post(route('admin.maintenance.run', $task))
        ->assertRedirect(route('login'));
})->with(['release-unpaid', 'complete-past']);

test('a task outside the list gives a 404 and runs no command', function (string $task) {
    Artisan::spy();

    $this->actingAs(userWithRole(RoleName::Admin))
        ->post('admin/maintenance/'.rawurlencode($task))
        ->assertNotFound();

    Artisan::shouldNotHaveReceived('call');
})->with(['db:wipe', 'migrate:fresh', 'bookings:release-unpaid', 'release-unpaid;ls', 'otra']);

test('the controller itself refuses a task outside the list', function () {
    app(MaintenanceController::class)('db:wipe');
})->throws(NotFoundHttpException::class);

test('the command name cannot be injected through the request body', function () {
    Artisan::shouldReceive('call')->once()->with('bookings:release-unpaid')->andReturn(0);
    Artisan::shouldReceive('output')->once()->andReturn("Reservas liberadas: 0.\n");

    $this->actingAs(userWithRole(RoleName::Admin))
        ->post(route('admin.maintenance.run', 'release-unpaid'), ['task' => 'db:wipe', 'command' => 'db:wipe'])
        ->assertRedirect(route('admin.index'))
        ->assertSessionHas('status', 'Reservas liberadas: 0.');
});
