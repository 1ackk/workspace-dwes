<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resource;
use App\Services\Booking\BookingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->service = app(BookingService::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function pendingUntil(string $expiresAt, array $attributes = []): Booking
{
    return Booking::factory()->create(['expires_at' => $expiresAt, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function confirmedEndingAt(string $end, array $attributes = []): Booking
{
    $endAt = Carbon::parse($end);

    return Booking::factory()->confirmed()
        ->withSlot(Resource::factory()->create(), $endAt->copy()->subHour(), $endAt)
        ->create($attributes);
}

/**
 * Ejecuta $change una sola vez, justo después de la primera lectura (SELECT).
 */
function afterFirstSelect(Closure $change): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use ($change, &$done): void {
        if (! $done && str_starts_with($query->sql, 'select')) {
            $done = true;
            $change();
        }
    });
}

describe('releaseUnpaid', function () {
    test('expires a pending booking whose hold has passed and counts it', function () {
        $booking = pendingUntil('2026-12-01 08:45:00');

        expect($this->service->releaseUnpaid(now()))->toBe(1)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Expired);
    });

    test('a hold that ends exactly now is not released yet', function () {
        Carbon::setTestNow('2026-12-01 09:00:00');
        $booking = pendingUntil('2026-12-01 09:00:00');

        expect($this->service->releaseUnpaid(now()))->toBe(0)
            ->and($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    });

    test('a hold that ended one second ago is released', function () {
        Carbon::setTestNow('2026-12-01 09:00:00');
        $booking = pendingUntil('2026-12-01 08:59:59');

        expect($this->service->releaseUnpaid(now()))->toBe(1)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Expired);
    });

    test('a hold still in force is not touched', function () {
        $booking = pendingUntil('2026-12-01 09:10:00');

        expect($this->service->releaseUnpaid(now()))->toBe(0)
            ->and($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    });

    test('other states are not touched even with a past expiry', function (BookingStatus $status) {
        $booking = pendingUntil('2026-12-01 08:00:00', ['status' => $status]);

        expect($this->service->releaseUnpaid(now()))->toBe(0)
            ->and($booking->fresh()->status)->toBe($status);
    })->with([BookingStatus::Confirmed, BookingStatus::Cancelled, BookingStatus::Completed, BookingStatus::Expired]);

    test('a pending booking without expiry and a soft deleted one are not touched', function () {
        $withoutExpiry = Booking::factory()->create(['expires_at' => null]);
        $deleted = pendingUntil('2026-12-01 08:00:00', ['deleted_at' => '2026-11-30 10:00:00']);

        expect($this->service->releaseUnpaid(now()))->toBe(0)
            ->and($withoutExpiry->fresh()->status)->toBe(BookingStatus::PendingPayment)
            ->and(Booking::withTrashed()->find($deleted->id)->status)->toBe(BookingStatus::PendingPayment);
    });

    test('is idempotent: a second run changes nothing', function () {
        pendingUntil('2026-12-01 08:00:00');
        pendingUntil('2026-12-01 08:30:00');

        expect($this->service->releaseUnpaid(now()))->toBe(2)
            ->and($this->service->releaseUnpaid(now()))->toBe(0);
    });

    test('countUnpaid only counts', function () {
        $booking = pendingUntil('2026-12-01 08:00:00');

        expect($this->service->countUnpaid(now()))->toBe(1)
            ->and($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    });

    test('does not overwrite a booking confirmed right after being read', function () {
        $booking = pendingUntil('2026-12-01 08:00:00');

        // Simula otra petición que confirma el pago justo después de que el servicio lea los
        // ids de las candidatas. El UPDATE condicional ya no encuentra una reserva pendiente:
        // no la pisa y tampoco la cuenta como cambiada.
        afterFirstSelect(fn () => Booking::query()->whereKey($booking->id)->update(['status' => BookingStatus::Confirmed]));

        expect($this->service->releaseUnpaid(now()))->toBe(0)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    });
});

describe('completePast', function () {
    test('completes a confirmed booking whose slots have all ended and counts it', function () {
        $booking = confirmedEndingAt('2026-12-01 08:00:00');

        expect($this->service->completePast(now()))->toBe(1)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Completed);
    });

    test('a slot that ends exactly now counts as ended', function () {
        Carbon::setTestNow('2026-12-01 09:00:00');
        $booking = confirmedEndingAt('2026-12-01 09:00:00');

        expect($this->service->completePast(now()))->toBe(1)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Completed);
    });

    test('a slot that ends one second later has not ended', function () {
        Carbon::setTestNow('2026-12-01 09:00:00');
        $booking = confirmedEndingAt('2026-12-01 09:00:01');

        expect($this->service->completePast(now()))->toBe(0)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    });

    test('a booking with one slot still to end is not completed', function () {
        $booking = Booking::factory()->confirmed()
            ->withSlot(Resource::factory()->create(), Carbon::parse('2026-11-30 10:00'), Carbon::parse('2026-11-30 11:00'))
            ->withSlot(Resource::factory()->create(), Carbon::parse('2026-12-02 10:00'), Carbon::parse('2026-12-02 11:00'))
            ->create();

        expect($this->service->completePast(now()))->toBe(0)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    });

    test('a confirmed booking without slots is not completed', function () {
        $booking = Booking::factory()->confirmed()->create();

        expect($this->service->completePast(now()))->toBe(0)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    });

    test('other states are not touched even with past slots', function (BookingStatus $status) {
        $booking = confirmedEndingAt('2026-12-01 08:00:00', ['status' => $status]);

        expect($this->service->completePast(now()))->toBe(0)
            ->and($booking->fresh()->status)->toBe($status);
    })->with([BookingStatus::PendingPayment, BookingStatus::Cancelled, BookingStatus::Expired]);

    test('a soft deleted booking is not touched', function () {
        $booking = confirmedEndingAt('2026-12-01 08:00:00', ['deleted_at' => '2026-11-30 10:00:00']);

        expect($this->service->completePast(now()))->toBe(0)
            ->and(Booking::withTrashed()->find($booking->id)->status)->toBe(BookingStatus::Confirmed);
    });

    test('is idempotent: a second run changes nothing', function () {
        confirmedEndingAt('2026-12-01 08:00:00');

        expect($this->service->completePast(now()))->toBe(1)
            ->and($this->service->completePast(now()))->toBe(0);
    });

    test('countPast only counts', function () {
        $booking = confirmedEndingAt('2026-12-01 08:00:00');

        expect($this->service->countPast(now()))->toBe(1)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    });

    test('does not overwrite a booking cancelled right after being read', function () {
        $booking = confirmedEndingAt('2026-12-01 08:00:00');

        // Simula otra petición que cancela la reserva justo después de que el servicio lea los
        // ids de las candidatas. El UPDATE condicional ya no encuentra una reserva confirmada.
        afterFirstSelect(fn () => Booking::query()->whereKey($booking->id)->update(['status' => BookingStatus::Cancelled]));

        expect($this->service->completePast(now()))->toBe(0)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    });
});
