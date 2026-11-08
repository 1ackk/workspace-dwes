<?php

use App\Enums\BookingStatus;
use App\Exceptions\BookingException;
use App\Exceptions\BookingNotCancellableException;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use App\Services\Booking\BookingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->service = app(BookingService::class);
});

function spaceResource(int $noticeHours): Resource
{
    return Resource::factory()
        ->for(Space::factory()->state(['cancellation_notice_hours' => $noticeHours]))
        ->create();
}

/**
 * Reserva con una franja de una hora por recurso; cada una empieza a los minutos indicados desde ahora.
 *
 * @param  array<int, array{0: App\Models\Resource, 1: int}>  $slots
 * @param  array<string, mixed>  $attributes
 */
function bookingToCancel(array $slots, array $attributes = []): Booking
{
    $factory = Booking::factory()->confirmed();

    foreach ($slots as [$resource, $minutes]) {
        $start = now()->addMinutes($minutes);
        $factory = $factory->withSlot($resource, $start, $start->copy()->addHour());
    }

    return $factory->create($attributes);
}

function pastDeadlineMessage(int $hours, string $date, string $time): string
{
    return __('Solo se puede cancelar con :hours horas de antelación (hasta el :date a las :time).', [
        'hours' => $hours,
        'date' => $date,
        'time' => $time,
    ]);
}

test('cancels a booking and stores when', function () {
    $booking = bookingToCancel([[spaceResource(24), 3 * 24 * 60]]);

    $cancelled = $this->service->cancel($booking);

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($cancelled->cancelled_at->toDateTimeString())->toBe('2026-12-01 09:00:00')
        ->and($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->fresh()->cancelled_at->toDateTimeString())->toBe('2026-12-01 09:00:00');
});

test('a pending booking still valid can be cancelled', function () {
    $booking = bookingToCancel([[spaceResource(24), 3 * 24 * 60]], [
        'status' => BookingStatus::PendingPayment,
        'expires_at' => now()->addMinutes(10),
    ]);

    expect($this->service->cancel($booking)->status)->toBe(BookingStatus::Cancelled);
});

test('the slot is free again after cancelling', function () {
    $resource = spaceResource(24);
    $booking = bookingToCancel([[$resource, 3 * 24 * 60]]);
    $start = now()->addDays(3);
    $isFree = fn () => Resource::query()->whereKey($resource->id)->available($start, $start->copy()->addHour())->exists();

    expect($isFree())->toBeFalse();

    $this->service->cancel($booking);

    expect($isFree())->toBeTrue();
});

describe('the notice period', function () {
    test('can be cancelled at the exact limit', function () {
        $booking = bookingToCancel([[spaceResource(24), 24 * 60]]);

        expect($this->service->cancel($booking)->status)->toBe(BookingStatus::Cancelled);
    });

    test('one minute inside the limit is rejected and tells the notice and the deadline', function () {
        $booking = bookingToCancel([[spaceResource(24), 24 * 60 - 1]]);

        expect(fn () => $this->service->cancel($booking))
            ->toThrow(BookingNotCancellableException::class, pastDeadlineMessage(24, '01/12/2026', '08:59'));

        expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->fresh()->cancelled_at)->toBeNull();
    });

    test('works with a non standard notice', function (int $minutes, bool $cancellable) {
        $booking = bookingToCancel([[spaceResource(12), $minutes]]);

        if ($cancellable) {
            expect($this->service->cancel($booking)->status)->toBe(BookingStatus::Cancelled);

            return;
        }

        expect(fn () => $this->service->cancel($booking))
            ->toThrow(BookingNotCancellableException::class, pastDeadlineMessage(12, '01/12/2026', '08:59'));
    })->with([
        'en el límite exacto de 12 h' => [12 * 60, true],
        'un minuto dentro de 12 h' => [12 * 60 - 1, false],
    ]);

    test('with two spaces the strictest notice rules, whatever the order', function (array $notices) {
        $booking = bookingToCancel(array_map(fn (int $hours) => [spaceResource($hours), 30 * 60], $notices));

        // La franja empieza el 02/12 a las 15:00; 48 horas antes es el 30/11 a las 15:00.
        expect(fn () => $this->service->cancel($booking))
            ->toThrow(BookingNotCancellableException::class, pastDeadlineMessage(48, '30/11/2026', '15:00'));

        expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    })->with([
        '24 y 48' => [[24, 48]],
        '48 y 24' => [[48, 24]],
    ]);

    test('with two spaces it can be cancelled if both notices are met', function () {
        $booking = bookingToCancel([
            [spaceResource(24), 50 * 60],
            [spaceResource(48), 50 * 60],
        ]);

        expect($this->service->cancel($booking)->status)->toBe(BookingStatus::Cancelled);
    });

    test('the deadline shown is the earliest one, even from a later slot', function () {
        $booking = bookingToCancel([
            [spaceResource(12), 13 * 60],
            [spaceResource(48), 47 * 60],
        ]);

        // 47 h desde ahora son el 03/12 a las 08:00; 48 horas antes, el 01/12 a las 08:00.
        expect(fn () => $this->service->cancel($booking))
            ->toThrow(BookingNotCancellableException::class, pastDeadlineMessage(48, '01/12/2026', '08:00'));
    });
});

describe('bookings that can no longer be cancelled', function () {
    test('explains why and changes nothing', function (array $attributes, string $message) {
        $booking = bookingToCancel([[spaceResource(24), 3 * 24 * 60]], $attributes);
        $statusBefore = $booking->fresh()->status;

        expect(fn () => $this->service->cancel($booking))
            ->toThrow(BookingNotCancellableException::class, __($message));

        expect($booking->fresh()->status)->toBe($statusBefore);
    })->with([
        'ya cancelada' => [fn () => ['status' => BookingStatus::Cancelled, 'expires_at' => null], 'La reserva ya está cancelada.'],
        'caducada' => [fn () => ['status' => BookingStatus::Expired, 'expires_at' => now()->subMinute()], 'La reserva ha caducado.'],
        'completada' => [fn () => ['status' => BookingStatus::Completed, 'expires_at' => null], 'La reserva ya está completada.'],
        'pendiente caducada sin limpiar' => [fn () => ['status' => BookingStatus::PendingPayment, 'expires_at' => now()->subMinute()], 'La reserva ha caducado.'],
    ]);

    test('a second cancellation is rejected and keeps the first date', function () {
        $booking = bookingToCancel([[spaceResource(24), 3 * 24 * 60]]);
        $this->service->cancel($booking);

        Carbon::setTestNow(now()->addMinutes(5));

        expect(fn () => $this->service->cancel($booking))
            ->toThrow(BookingNotCancellableException::class, __('La reserva ya está cancelada.'));

        expect($booking->fresh()->cancelled_at->toDateTimeString())->toBe('2026-12-01 09:00:00');
    });

    test('uses the state in the database, not the model it receives', function () {
        $booking = bookingToCancel([[spaceResource(24), 3 * 24 * 60]]);
        Booking::query()->whereKey($booking->id)->update(['status' => BookingStatus::Cancelled]);

        expect(fn () => $this->service->cancel($booking))
            ->toThrow(BookingNotCancellableException::class, __('La reserva ya está cancelada.'));
    });

    test('a soft deleted booking is not found', function () {
        $booking = bookingToCancel([[spaceResource(24), 3 * 24 * 60]]);
        $booking->delete();

        expect(fn () => $this->service->cancel($booking))->toThrow(ModelNotFoundException::class);
    });

    test('the exception is a domain error', function () {
        expect(is_subclass_of(BookingNotCancellableException::class, BookingException::class))->toBeTrue();
    });
});

test('the first query locks the booking', function () {
    $booking = bookingToCancel([[spaceResource(24), 3 * 24 * 60]]);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->service->cancel($booking);

    expect($queries[0])->toContain('from "bookings"')
        ->toContain('"bookings"."id" = ?')
        ->not->toContain('not exists');
});
