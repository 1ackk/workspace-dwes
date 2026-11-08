<?php

use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

function bookingOf(User $owner, string $resourceName = 'Sala Azul'): Booking
{
    $resource = Resource::factory()->create(['name' => $resourceName, 'hourly_rate' => 10]);
    $start = now()->addDay()->setTime(10, 0);

    return Booking::factory()->confirmed()
        ->for($owner, 'user')
        ->withSlot($resource, $start, $start->copy()->addHours(2))
        ->create(['date' => '2026-12-01']);
}

test('a user sees only their bookings with date, resources, status and total', function () {
    $user = userWithRole(RoleName::Member);
    bookingOf($user);
    bookingOf(userWithRole(RoleName::Member), 'Sala Ajena');

    $this->actingAs($user)
        ->get(route('my-bookings.index'))
        ->assertOk()
        ->assertSee('01/12/2026')
        ->assertSee('Sala Azul')
        ->assertSee('Confirmada')
        ->assertSee('20,00 €')
        ->assertDontSee('Sala Ajena');
});

test('the list is paginated', function () {
    $user = userWithRole(RoleName::Member);
    Booking::factory()->count(11)->for($user, 'user')->create();

    $this->actingAs($user)
        ->get(route('my-bookings.index'))
        ->assertOk()
        ->assertViewHas('bookings', fn ($bookings) => $bookings->count() === 10 && $bookings->total() === 11);
});

test('the list and the detail load their relations eagerly', function () {
    $user = userWithRole(RoleName::Member);
    $booking = bookingOf($user);
    bookingOf($user, 'Sala Verde');

    Model::preventLazyLoading();

    $this->actingAs($user)->get(route('my-bookings.index'))->assertOk();
    $this->actingAs($user)->get(route('my-bookings.show', $booking))->assertOk();
});

test('shows the empty state', function () {
    $this->actingAs(userWithRole(RoleName::Member))
        ->get(route('my-bookings.index'))
        ->assertOk()
        ->assertSee(__('Todavía no tienes reservas.'));
});

test('the navigation links to my bookings', function () {
    $this->actingAs(userWithRole(RoleName::Member))
        ->get(route('home'))
        ->assertSee(route('my-bookings.index'));
});

test('a user sees the detail of their booking', function () {
    $user = userWithRole(RoleName::Member);
    $booking = bookingOf($user);

    $this->actingAs($user)
        ->get(route('my-bookings.show', $booking))
        ->assertOk()
        ->assertSee('Sala Azul')
        ->assertSee('20,00 €');
});

test('the detail of another user booking returns 403', function () {
    $booking = bookingOf(userWithRole(RoleName::Member));

    $this->actingAs(userWithRole(RoleName::Member))
        ->get(route('my-bookings.show', $booking))
        ->assertForbidden();
});

test('an accountant sees their bookings and gets 403 on another one', function () {
    $accountant = userWithRole(RoleName::Accountant);
    $own = bookingOf($accountant);
    $other = bookingOf(userWithRole(RoleName::Member), 'Sala Ajena');

    $this->actingAs($accountant)
        ->get(route('my-bookings.index'))
        ->assertOk()
        ->assertSee('Sala Azul')
        ->assertDontSee('Sala Ajena');

    $this->actingAs($accountant)->get(route('my-bookings.show', $own))->assertOk();
    $this->actingAs($accountant)->get(route('my-bookings.show', $other))->assertForbidden();
});

test('a guest goes to the login', function () {
    $booking = bookingOf(userWithRole(RoleName::Member));

    $this->get(route('my-bookings.index'))->assertRedirect(route('login'));
    $this->get(route('my-bookings.show', $booking))->assertRedirect(route('login'));
});
