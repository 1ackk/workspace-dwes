<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');
});

function resourceWithNotice(int $hours): Resource
{
    return Resource::factory()
        ->for(Space::factory()->state(['cancellation_notice_hours' => $hours]))
        ->create();
}

/**
 * Reserva con una franja de una hora por recurso; cada franja empieza a los minutos indicados desde ahora.
 *
 * @param  array<int, array{0: resource, 1: int}>  $slots
 * @param  array<string, mixed>  $attributes
 */
function bookingStartingIn(array $slots, array $attributes = []): Booking
{
    $factory = Booking::factory()->confirmed();

    foreach ($slots as [$resource, $minutes]) {
        $start = now()->addMinutes($minutes);
        $factory = $factory->withSlot($resource, $start, $start->copy()->addHour());
    }

    return $factory->create($attributes);
}

function isCancellable(Booking $booking): bool
{
    return Booking::query()->whereKey($booking->id)->cancellable()->exists();
}

describe('upcoming', function () {
    test('includes today and later dates, but not earlier ones', function (string $date, bool $expected) {
        $booking = Booking::factory()->create(['date' => $date]);

        expect(Booking::query()->upcoming()->whereKey($booking->id)->exists())->toBe($expected);
    })->with([
        'ayer' => ['2026-11-30', false],
        'hoy' => ['2026-12-01', true],
        'mañana' => ['2026-12-02', true],
    ]);

    test('still includes today at the last minute of the day', function () {
        Carbon::setTestNow('2026-12-01 23:59:00');
        $booking = Booking::factory()->create(['date' => '2026-12-01']);

        expect(Booking::query()->upcoming()->whereKey($booking->id)->exists())->toBeTrue();
    });
});

describe('cancellable', function () {
    test('respects the notice of the space around the exact limit', function (int $hours, int $offset, bool $expected) {
        $booking = bookingStartingIn([[resourceWithNotice($hours), $hours * 60 + $offset]]);

        expect(isCancellable($booking))->toBe($expected);
    })->with([
        '24 h, un minuto antes del límite' => [24, 1, true],
        '24 h, en el límite exacto' => [24, 0, true],
        '24 h, un minuto después del límite' => [24, -1, false],
        '12 h, un minuto antes del límite' => [12, 1, true],
        '12 h, en el límite exacto' => [12, 0, true],
        '12 h, un minuto después del límite' => [12, -1, false],
        '7 h, en el límite exacto' => [7, 0, true],
        '7 h, un minuto después del límite' => [7, -1, false],
        'sin antelación, en el límite exacto' => [0, 0, true],
        'sin antelación, ya empezada' => [0, -1, false],
    ]);

    test('depends on the status of the booking', function (array $attributes, bool $expected) {
        $booking = bookingStartingIn([[resourceWithNotice(24), 3 * 24 * 60]], $attributes);

        expect(isCancellable($booking))->toBe($expected);
    })->with([
        'pendiente vigente' => [fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => now()->addMinute()], true],
        'pendiente caducada sin limpiar' => [fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => now()->subMinute()], false],
        'pendiente sin caducidad' => [fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => null], true],
        'confirmada' => [fn () => ['status' => BookingStatus::Confirmed, 'expires_at' => null], true],
        'cancelada' => [fn () => ['status' => BookingStatus::Cancelled, 'expires_at' => null], false],
        'caducada' => [fn () => ['status' => BookingStatus::Expired, 'expires_at' => now()->subMinute()], false],
        'completada' => [fn () => ['status' => BookingStatus::Completed, 'expires_at' => null], false],
    ]);

    test('excludes a soft deleted booking', function () {
        $booking = bookingStartingIn([[resourceWithNotice(24), 3 * 24 * 60]]);
        $booking->delete();

        expect(isCancellable($booking))->toBeFalse();
    });

    test('a booking is not cancellable if one of its spaces is out of time', function () {
        $booking = bookingStartingIn([
            [resourceWithNotice(24), 30 * 60],
            [resourceWithNotice(48), 30 * 60],
        ]);

        expect(isCancellable($booking))->toBeFalse();
    });

    test('a booking is cancellable if all its spaces are on time', function () {
        $booking = bookingStartingIn([
            [resourceWithNotice(24), 50 * 60],
            [resourceWithNotice(48), 50 * 60],
        ]);

        expect(isCancellable($booking))->toBeTrue();
    });

    test('each slot is measured against the notice of its own space', function () {
        $sooner = bookingStartingIn([
            [resourceWithNotice(12), 13 * 60],
            [resourceWithNotice(48), 49 * 60],
        ]);
        $later = bookingStartingIn([
            [resourceWithNotice(12), 11 * 60],
            [resourceWithNotice(48), 49 * 60],
        ]);

        expect(isCancellable($sooner))->toBeTrue()
            ->and(isCancellable($later))->toBeFalse();
    });

    test('mixes a non standard notice with the others at the exact limit', function () {
        $onTime = bookingStartingIn([
            [resourceWithNotice(12), 12 * 60],
            [resourceWithNotice(48), 48 * 60],
        ]);
        $oneMinuteLate = bookingStartingIn([
            [resourceWithNotice(12), 12 * 60],
            [resourceWithNotice(48), 48 * 60 - 1],
        ]);

        expect(isCancellable($onTime))->toBeTrue()
            ->and(isCancellable($oneMinuteLate))->toBeFalse();
    });

    test('stops being cancellable as time goes by', function () {
        $booking = bookingStartingIn([[resourceWithNotice(24), 25 * 60]]);

        expect(isCancellable($booking))->toBeTrue();

        Carbon::setTestNow(now()->addHours(2));

        expect(isCancellable($booking))->toBeFalse();
    });
});
