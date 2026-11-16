<?php

use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
    $start = Carbon::parse('2026-12-01 10:00');

    return Booking::factory()->confirmed()
        ->for($owner, 'user')
        ->withSlot($resource, $start, $start->copy()->addHours(2))
        ->create();
}

function filteredDates($test, array $filter): array
{
    return $test->actingAs($test->user)
        ->get(route('my-bookings.index', $filter))
        ->assertOk()
        ->viewData('bookings')
        ->getCollection()
        ->map(fn (Booking $booking) => $booking->date->toDateString())
        ->all();
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
        ->assertSee(__('No hay reservas que mostrar.'));
});

describe('date filter', function () {
    beforeEach(function () {
        $this->user = userWithRole(RoleName::Member);

        foreach (['2026-12-01', '2026-12-10', '2026-12-20'] as $date) {
            Booking::factory()->for($this->user, 'user')->create(['date' => $date]);
        }
    });

    test('shows the date fields in the list', function () {
        $this->actingAs($this->user)
            ->get(route('my-bookings.index'))
            ->assertSee('name="from"', false)
            ->assertSee('name="to"', false)
            ->assertSee('type="date"', false);
    });

    test('filters from a date, including it', function () {
        expect(filteredDates($this, ['from' => '2026-12-10']))->toBe(['2026-12-20', '2026-12-10']);
    });

    test('filters up to a date, including it, without a start date', function () {
        expect(filteredDates($this, ['to' => '2026-12-10']))->toBe(['2026-12-10', '2026-12-01']);
    });

    test('filters between two dates', function () {
        expect(filteredDates($this, ['from' => '2026-12-05', 'to' => '2026-12-15']))->toBe(['2026-12-10']);
    });

    test('keeps the chosen dates in the form', function () {
        $this->actingAs($this->user)
            ->get(route('my-bookings.index', ['from' => '2026-12-05']))
            ->assertSee('value="2026-12-05"', false);
    });

    test('keeps the filter in the pagination links', function () {
        Booking::factory()->count(11)->for($this->user, 'user')->create(['date' => '2026-12-25']);

        $this->actingAs($this->user)
            ->get(route('my-bookings.index', ['from' => '2026-12-20']))
            ->assertSee('from=2026-12-20&amp;page=2', false);
    });

    test('never includes bookings of other users', function () {
        Booking::factory()->create(['date' => '2026-12-10']);

        expect(filteredDates($this, ['from' => '2026-12-10', 'to' => '2026-12-10']))->toBe(['2026-12-10']);
    });

    test('rejects a range that ends before it starts and shows the error', function () {
        $this->actingAs($this->user)
            ->followingRedirects()
            ->get(route('my-bookings.index', ['from' => '2026-12-20', 'to' => '2026-12-01']))
            ->assertOk()
            ->assertSee(__('La fecha final no puede ser anterior a la inicial.'));
    });

    test('rejects an invalid date and goes back to the clean list', function () {
        $this->actingAs($this->user)
            ->get(route('my-bookings.index', ['from' => 'ayer']))
            ->assertRedirect(route('my-bookings.index'))
            ->assertSessionHasErrors(['from' => __('La fecha inicial no es válida.')]);
    });
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
