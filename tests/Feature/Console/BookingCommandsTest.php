<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resource;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');
});

function lapsedBooking(): Booking
{
    return Booking::factory()->create(['expires_at' => '2026-12-01 08:00:00']);
}

function finishedBooking(): Booking
{
    return Booking::factory()->confirmed()
        ->withSlot(Resource::factory()->create(), Carbon::parse('2026-11-30 10:00'), Carbon::parse('2026-11-30 11:00'))
        ->create();
}

function scheduledEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, $command));
}

describe('bookings:release-unpaid', function () {
    test('releases and reports how many, and a second run says 0', function () {
        $booking = lapsedBooking();

        $this->artisan('bookings:release-unpaid')
            ->expectsOutput('Reservas liberadas: 1.')
            ->assertExitCode(0);

        expect($booking->fresh()->status)->toBe(BookingStatus::Expired);

        $this->artisan('bookings:release-unpaid')
            ->expectsOutput('Reservas liberadas: 0.')
            ->assertExitCode(0);
    });

    test('--dry-run says how many and changes nothing', function () {
        $booking = lapsedBooking();

        $this->artisan('bookings:release-unpaid', ['--dry-run' => true])
            ->expectsOutput('Simulación: reservas que se liberarían: 1. No se ha cambiado nada.')
            ->assertExitCode(0);

        expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    });
});

describe('bookings:complete-past', function () {
    test('completes and reports how many, and a second run says 0', function () {
        $booking = finishedBooking();

        $this->artisan('bookings:complete-past')
            ->expectsOutput('Reservas completadas: 1.')
            ->assertExitCode(0);

        expect($booking->fresh()->status)->toBe(BookingStatus::Completed);

        $this->artisan('bookings:complete-past')
            ->expectsOutput('Reservas completadas: 0.')
            ->assertExitCode(0);
    });

    test('--dry-run says how many and changes nothing', function () {
        $booking = finishedBooking();

        $this->artisan('bookings:complete-past', ['--dry-run' => true])
            ->expectsOutput('Simulación: reservas que se completarían: 1. No se ha cambiado nada.')
            ->assertExitCode(0);

        expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    });
});

describe('schedule', function () {
    test('runs each command with its frequency and without overlapping', function (string $command, string $expression) {
        $event = scheduledEvent($command);

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe($expression)
            ->and($event->withoutOverlapping)->toBeTrue();
    })->with([
        'release-unpaid cada minuto' => ['bookings:release-unpaid', '* * * * *'],
        'complete-past cada hora' => ['bookings:complete-past', '0 * * * *'],
    ]);

    test('the reports are not scheduled', function (string $command) {
        expect(scheduledEvent($command))->toBeNull();
    })->with(['invoices:list-overdue', 'occupancy:report']);
});
