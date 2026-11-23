<?php

use App\Enums\BookingStatus;
use App\Enums\RoleName;
use App\Events\BookingConfirmed;
use App\Jobs\SendBookingConfirmedMailJob;
use App\Livewire\BookingCalendar;
use App\Mail\BookingConfirmedMail;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\Slot;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Martes 01/12/2026 a las 09:00. Por defecto la pantalla abre el día siguiente.
    Carbon::setTestNow('2026-12-01 09:00:00');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);

    $this->member = userWithRole(RoleName::Member);
    $this->space = Space::factory()->create(['name' => 'Planta Baja', 'opens_at' => '08:00:00', 'closes_at' => '20:00:00']);
    $this->room = Resource::factory()->for($this->space)->create(['name' => 'Sala Azul', 'hourly_rate' => 12]);
});

/**
 * El calendario como $user, con la Sala Azul elegida.
 */
function calendarFor(User $user)
{
    return Livewire::actingAs($user)->test(BookingCalendar::class)->set('resourceId', test()->room->id);
}

describe('access', function () {
    test('a guest goes to the login', function () {
        $this->get(route('calendar'))->assertRedirect(route('login'));
    });

    test('roles without bookings.create receive a 403', function (RoleName $role) {
        $this->actingAs(userWithRole($role))->get(route('calendar'))->assertForbidden();
    })->with(['responsable' => RoleName::SpaceManager, 'contable' => RoleName::Accountant]);

    test('a user who can book sees the screen, linked from the menu and the panel', function (RoleName $role) {
        $user = userWithRole($role);

        $this->actingAs($user)->get(route('calendar'))->assertOk()->assertSeeLivewire(BookingCalendar::class);
        $this->actingAs($user)->get(route('dashboard'))->assertSee(route('calendar'));
    })->with(['usuario' => RoleName::Member, 'recepción' => RoleName::Reception, 'administrador' => RoleName::Admin]);

    test('the menu does not link the calendar for roles that cannot book', function () {
        $this->actingAs(userWithRole(RoleName::Accountant))->get(route('dashboard'))->assertDontSee(route('calendar'));
    });
});

describe('selectors and grid', function () {
    test('starts tomorrow with the first active resource, labelled with its space', function () {
        Resource::factory()->for($this->space)->create(['name' => 'Puesto Retirado', 'active' => false]);

        Livewire::actingAs($this->member)->test(BookingCalendar::class)
            ->assertSet('day', '2026-12-02')
            ->assertSet('resourceId', $this->room->id)
            ->assertSee('Planta Baja · Sala Azul')
            ->assertDontSee('Puesto Retirado');
    });

    test('shows each cell with its state as text and an accessible label', function () {
        Booking::factory()->confirmed()->withSlot($this->room, Carbon::parse('2026-12-02 10:00'), Carbon::parse('2026-12-02 11:00'))->create();

        calendarFor($this->member)
            ->assertSee('aria-label="09:30–10:00, Libre"', false)
            ->assertSee('aria-label="10:00–10:30, Ocupada"', false)
            ->assertSee('Ocupada');
    });

    test('a past day is rejected and shows no grid', function () {
        calendarFor($this->member)
            ->set('day', '2026-11-30')
            ->assertHasErrors(['day' => 'after_or_equal'])
            ->assertSee('No se puede reservar un día pasado.')
            ->assertSee('Elige un recurso y un día para ver sus franjas.');
    });

    test('today shows the past cells as past', function () {
        calendarFor($this->member)
            ->set('day', '2026-12-01')
            ->assertHasNoErrors()
            ->assertSee('aria-label="09:00–09:30, Pasada"', false)
            ->assertSee('aria-label="09:30–10:00, Libre"', false);
    });
});

describe('selection', function () {
    test('two clicks choose a contiguous range and show its price', function () {
        calendarFor($this->member)
            ->call('select', '10:00')
            ->assertSet('selectionStart', '10:00')
            ->assertSet('selectionEnd', null)
            ->call('select', '11:00')
            ->assertSet('selectionStart', '10:00')
            ->assertSet('selectionEnd', '11:00')
            ->assertSee('Tramo elegido: de 10:00 a 11:30.')
            ->assertSee('Precio: 18,00 €');
    });

    test('a second click before the first chooses the range the other way round', function () {
        calendarFor($this->member)
            ->call('select', '11:00')
            ->call('select', '10:00')
            ->assertSet('selectionStart', '10:00')
            ->assertSet('selectionEnd', '11:00');
    });

    test('the same cell twice is a single 30-minute slot', function () {
        calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '10:00')
            ->assertSee('Tramo elegido: de 10:00 a 10:30.');
    });

    test('a range that crosses a busy cell is rejected and cleared', function () {
        Booking::factory()->confirmed()->withSlot($this->room, Carbon::parse('2026-12-02 10:30'), Carbon::parse('2026-12-02 11:00'))->create();

        calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '11:00')
            ->assertSet('selectionStart', null)
            ->assertSet('selectionEnd', null)
            ->assertSee('El tramo cruza una franja que no está libre.');
    });

    test('a past or busy cell cannot be chosen', function () {
        calendarFor($this->member)
            ->set('day', '2026-12-01')
            ->call('select', '08:30')
            ->assertSet('selectionStart', null)
            ->assertSee('Esa franja no está libre.')
            ->call('select', '23:00')
            ->assertSet('selectionStart', null);
    });

    test('a third click starts a new selection', function () {
        calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '11:00')
            ->call('select', '15:00')
            ->assertSet('selectionStart', '15:00')
            ->assertSet('selectionEnd', null);
    });

    test('changing the resource or the day starts over', function () {
        $other = Resource::factory()->for($this->space)->create();

        calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '11:00')
            ->set('resourceId', $other->id)
            ->assertSet('selectionStart', null)
            ->assertSet('selectionEnd', null)
            ->call('select', '10:00')
            ->set('day', '2026-12-03')
            ->assertSet('selectionStart', null);
    });

    test('the preview price is the one of PriceCalculator, surcharges included', function () {
        Tariff::factory()->afternoon()->create();
        $start = Carbon::parse('2026-12-02 14:00');
        $end = Carbon::parse('2026-12-02 16:30');
        $expected = app(PriceCalculator::class)->priceSlot($this->room, $start, $end)->decimal();

        calendarFor($this->member)
            ->call('select', '14:00')
            ->call('select', '16:00')
            ->assertSee('Precio: '.number_format((float) $expected, 2, ',', '.').' €');

        expect($expected)->toBe('32.70');
    });
});

describe('reserve', function () {
    test('creates the pending booking with the right slot and its hold', function () {
        $component = calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '11:00')
            ->call('reserve')
            ->assertHasNoErrors()
            ->assertSee('Reserva retenida')
            ->assertSee('hasta las 09:15')
            ->assertSee('Pagar (simulado)');

        $booking = Booking::query()->sole();
        $slot = $booking->slots()->sole();

        expect($component->get('pendingBookingId'))->toBe($booking->id)
            ->and($booking->user_id)->toBe($this->member->id)
            ->and($booking->status)->toBe(BookingStatus::PendingPayment)
            ->and($booking->expires_at?->toDateTimeString())->toBe('2026-12-01 09:15:00')
            ->and($booking->total)->toBe('18.00')
            ->and($slot->start_at->toDateTimeString())->toBe('2026-12-02 10:00:00')
            ->and($slot->end_at->toDateTimeString())->toBe('2026-12-02 11:30:00');
    });

    test('without a complete range it asks for one', function () {
        calendarFor($this->member)
            ->call('select', '10:00')
            ->call('reserve')
            ->assertSee('Elige primero un tramo');

        expect(Booking::count())->toBe(0);
    });

    test('if another user takes the slot first, it shows the reason and refreshes the grid', function () {
        $component = calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '10:30');

        app(BookingService::class)->createPending(User::factory()->create(), [
            new Slot($this->room, Carbon::parse('2026-12-02 10:30'), Carbon::parse('2026-12-02 11:00')),
        ]);

        $component->call('reserve')
            ->assertOk()
            ->assertSee('Sala Azul no está disponible el 02/12/2026 de 10:00 a 11:00.')
            ->assertSee('aria-label="10:30–11:00, Ocupada"', false)
            ->assertSet('pendingBookingId', null);

        expect(Booking::count())->toBe(1);
    });

    test('the service rejects a slot outside the opening hours even if the client forces it', function () {
        calendarFor($this->member)
            ->set('selectionStart', '20:00')
            ->set('selectionEnd', '20:30')
            ->call('reserve')
            ->assertOk()
            ->assertSee('Sala Azul solo se puede reservar de 08:00 a 20:00.');

        expect(Booking::count())->toBe(0);
    });

    test('the service rejects a past slot even if the client forces it', function () {
        calendarFor($this->member)
            ->set('day', '2026-12-01')
            ->set('selectionStart', '08:00')
            ->set('selectionEnd', '08:30')
            ->call('reserve')
            ->assertOk()
            ->assertSee('La franja debe empezar después del momento actual.');

        expect(Booking::count())->toBe(0);
    });

    test('a forced past day, a forced time or an inactive resource fail validation', function (string $property, mixed $value, string $rule) {
        calendarFor($this->member)
            ->set('selectionStart', '10:00')
            ->set('selectionEnd', '10:00')
            ->set($property, $value instanceof Closure ? $value() : $value)
            ->call('reserve')
            ->assertHasErrors([$property => $rule]);

        expect(Booking::count())->toBe(0);
    })->with([
        'día pasado' => ['day', '2026-11-30', 'after_or_equal'],
        'día mal formado' => ['day', '02/12/2026', 'date_format'],
        'día que no existe' => ['day', '2026-02-31', 'date_format'],
        'hora mal formada' => ['selectionStart', '10h', 'date_format'],
        'hora que no existe' => ['selectionEnd', '25:00', 'date_format'],
        'recurso inactivo' => ['resourceId', fn () => Resource::factory()->create(['active' => false])->id, 'exists'],
    ]);
});

describe('pay', function () {
    test('confirms the booking, dispatches BookingConfirmed once and links to the detail', function () {
        Event::fake([BookingConfirmed::class]);

        $component = calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '10:30')
            ->call('reserve')
            ->call('pay')
            ->assertSee('Pago recibido: tu reserva está confirmada.')
            ->assertSet('pendingBookingId', null);

        $booking = Booking::query()->sole();

        expect($booking->status)->toBe(BookingStatus::Confirmed);
        $component->assertSet('confirmedBookingId', $booking->id)
            ->assertSee(route('my-bookings.show', $booking));
        Event::assertDispatchedTimes(BookingConfirmed::class, 1);
    });

    test('queues the confirmation mail', function () {
        Queue::fake();

        calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '10:00')
            ->call('reserve')
            ->call('pay');

        Queue::assertPushedOn('mail', SendBookingConfirmedMailJob::class);
    });

    test('with the hold expired it explains it and refreshes the grid', function () {
        $component = calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '10:00')
            ->call('reserve');

        Carbon::setTestNow('2026-12-01 09:20:00');

        $component->call('pay')
            ->assertSee('El tiempo para pagar la reserva ha terminado. Haz una reserva nueva.')
            ->assertSet('pendingBookingId', null)
            ->assertSee('aria-label="10:00–10:30, Libre"', false);

        expect(Booking::query()->sole()->status)->toBe(BookingStatus::PendingPayment);
    });

    test('the pending booking id cannot be changed from the client', function () {
        $foreign = Booking::factory()->create();

        calendarFor($this->member)->set('pendingBookingId', $foreign->id);
    })->throws(CannotUpdateLockedPropertyException::class);

    test('it authorizes again: a booking that is not yours gives a 403', function () {
        $component = calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '10:00')
            ->call('reserve');

        Booking::query()->update(['user_id' => User::factory()->create()->id]);

        $component->call('pay')->assertForbidden();

        expect(Booking::query()->sole()->status)->toBe(BookingStatus::PendingPayment);
    });

    test('it cannot be paid twice', function () {
        Event::fake([BookingConfirmed::class]);

        calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '10:00')
            ->call('reserve')
            ->call('pay')
            ->call('pay')
            ->assertSee('No hay ninguna reserva pendiente de pago.');

        Event::assertDispatchedTimes(BookingConfirmed::class, 1);
    });
});

describe('discard and hold', function () {
    test('discarding hides the panel and lets the hold expire on its own', function () {
        Mail::fake();

        calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '10:00')
            ->call('reserve')
            ->call('discard')
            ->assertSet('pendingBookingId', null)
            ->assertDontSee('Pagar (simulado)')
            ->assertSee('Reserva descartada. Las franjas quedarán libres a las 09:15');

        $booking = Booking::query()->sole();

        expect($booking->status)->toBe(BookingStatus::PendingPayment)
            ->and($booking->cancelled_at)->toBeNull();
        Mail::assertNothingSent();
    });

    test('the panel polls while there is a hold', function () {
        calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '10:00')
            ->call('reserve')
            ->assertSeeHtml('wire:poll.15s="checkHold"');
    });

    test('checkHold keeps a hold in force and drops an expired one', function () {
        $component = calendarFor($this->member)
            ->call('select', '10:00')
            ->call('select', '10:00')
            ->call('reserve');

        Carbon::setTestNow('2026-12-01 09:14:59');
        $component->call('checkHold')->assertSee('Reserva retenida');

        Carbon::setTestNow('2026-12-01 09:15:00');
        $component->call('checkHold')
            ->assertSet('pendingBookingId', null)
            ->assertSee('El tiempo para pagar la reserva ha terminado.');
    });

    test('checkHold does nothing without a hold', function () {
        calendarFor($this->member)->call('checkHold')->assertSet('problem', null);
    });
});

test('full flow with the database queue: choose, reserve, pay and the mail is sent', function () {
    config(['queue.default' => 'database']);
    Mail::fake();

    calendarFor($this->member)
        ->call('select', '16:00')
        ->call('select', '17:30')
        ->call('reserve')
        ->call('pay')
        ->assertSee('Pago recibido');

    Mail::assertNothingSent();
    expect(DB::table('jobs')->where('queue', 'mail')->count())->toBe(1);

    Artisan::call('queue:work', ['--queue' => 'mail', '--stop-when-empty' => true]);

    expect(DB::table('failed_jobs')->count())->toBe(0);
    Mail::assertSent(BookingConfirmedMail::class, fn (BookingConfirmedMail $mail) => $mail->hasTo($this->member->email)
        && str_contains($mail->render(), '02/12/2026 16:00 – 18:00'));
});
