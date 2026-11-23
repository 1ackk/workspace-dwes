<?php

use App\Enums\BookingStatus;
use App\Events\BookingConfirmed;
use App\Exceptions\BookingException;
use App\Exceptions\BookingNotConfirmableException;
use App\Jobs\SendBookingConfirmedMailJob;
use App\Listeners\SendBookingConfirmedMail;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->service = app(BookingService::class);
});

/**
 * Reserva pendiente cuya retención vence $minutes minutos después de ahora (negativo: ya venció).
 */
function pendingFor(int $minutes): Booking
{
    return Booking::factory()->create(['expires_at' => now()->addMinutes($minutes)]);
}

describe('confirm', function () {
    test('confirms a pending booking with a valid hold and clears expires_at', function () {
        $booking = pendingFor(10);

        $confirmed = $this->service->confirm($booking);

        expect($confirmed->status)->toBe(BookingStatus::Confirmed)
            ->and($confirmed->expires_at)->toBeNull()
            ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->fresh()->expires_at)->toBeNull();
    });

    test('rejects a booking that cannot be confirmed and leaves it untouched', function (Closure $makeBooking, BookingStatus $status, string $message) {
        $booking = $makeBooking();

        expect(fn () => $this->service->confirm($booking))
            ->toThrow(BookingNotConfirmableException::class, $message);

        expect($booking->fresh()->status)->toBe($status);
    })->with([
        'ya confirmada' => [fn () => Booking::factory()->confirmed()->create(), BookingStatus::Confirmed, 'La reserva ya está confirmada.'],
        'cancelada' => [fn () => Booking::factory()->cancelled()->create(), BookingStatus::Cancelled, 'La reserva está cancelada y no se puede confirmar.'],
        'caducada' => [fn () => Booking::factory()->expired()->create(), BookingStatus::Expired, 'La reserva ha caducado y no se puede confirmar.'],
        'completada' => [fn () => Booking::factory()->create(['status' => BookingStatus::Completed, 'expires_at' => null]), BookingStatus::Completed, 'La reserva ya está completada.'],
        'retención vencida sin limpiar' => [fn () => pendingFor(-1), BookingStatus::PendingPayment, 'El tiempo para pagar la reserva ha terminado. Haz una reserva nueva.'],
        'pendiente sin retención' => [fn () => Booking::factory()->create(['expires_at' => null]), BookingStatus::PendingPayment, 'El tiempo para pagar la reserva ha terminado. Haz una reserva nueva.'],
    ]);

    test('a hold that ends exactly now can no longer be confirmed', function () {
        $booking = Booking::factory()->create(['expires_at' => '2026-12-01 09:00:00']);

        expect(fn () => $this->service->confirm($booking))
            ->toThrow(BookingNotConfirmableException::class, 'El tiempo para pagar la reserva ha terminado. Haz una reserva nueva.');

        expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    });

    test('a hold that ends one second from now can still be confirmed', function () {
        $booking = Booking::factory()->create(['expires_at' => '2026-12-01 09:00:01']);

        expect($this->service->confirm($booking)->status)->toBe(BookingStatus::Confirmed);
    });

    test('a soft-deleted booking cannot be confirmed', function () {
        $booking = pendingFor(10);
        $booking->delete();

        expect(fn () => $this->service->confirm($booking))
            ->toThrow(BookingNotConfirmableException::class, 'La reserva no existe.');

        expect(Booking::withTrashed()->find($booking->id)->status)->toBe(BookingStatus::PendingPayment);
    });

    test('confirming twice fails the second time with the right message', function () {
        $booking = pendingFor(10);

        $this->service->confirm($booking);

        expect(fn () => $this->service->confirm($booking))
            ->toThrow(BookingNotConfirmableException::class, 'La reserva ya está confirmada.');
    });

    test('uses the state in the database, not the stale model received', function () {
        $booking = pendingFor(10);
        Booking::query()->whereKey($booking->id)->update(['status' => BookingStatus::Cancelled]);

        expect(fn () => $this->service->confirm($booking))
            ->toThrow(BookingNotConfirmableException::class, 'La reserva está cancelada y no se puede confirmar.');
    });

    test('the exception is a booking domain error', function () {
        expect(BookingNotConfirmableException::alreadyConfirmed())->toBeInstanceOf(BookingException::class);
    });
});

describe('event and queue', function () {
    test('dispatches BookingConfirmed once with the confirmed booking', function () {
        Event::fake([BookingConfirmed::class]);
        $booking = pendingFor(10);

        $this->service->confirm($booking);

        Event::assertDispatchedTimes(BookingConfirmed::class, 1);
        Event::assertDispatched(BookingConfirmed::class, fn (BookingConfirmed $event) => $event->booking->is($booking)
            && $event->booking->status === BookingStatus::Confirmed);
    });

    test('does not dispatch the event when the confirmation fails', function () {
        Event::fake([BookingConfirmed::class]);

        try {
            $this->service->confirm(pendingFor(-1));
        } catch (BookingNotConfirmableException) {
        }

        Event::assertNotDispatched(BookingConfirmed::class);
    });

    test('the listener queues exactly one mail job on the mail queue', function () {
        Queue::fake();
        $booking = pendingFor(10);

        $this->service->confirm($booking);

        Queue::assertPushedTimes(SendBookingConfirmedMailJob::class, 1);
        Queue::assertPushedOn('mail', SendBookingConfirmedMailJob::class, fn (SendBookingConfirmedMailJob $job) => $job->booking->is($booking));
    });

    test('nothing is queued when the confirmation fails', function () {
        Queue::fake();

        try {
            $this->service->confirm(Booking::factory()->cancelled()->create());
        } catch (BookingNotConfirmableException) {
        }

        Queue::assertNothingPushed();
    });

    test('the event is listened by SendBookingConfirmedMail', function () {
        Event::fake();

        Event::assertListening(BookingConfirmed::class, SendBookingConfirmedMail::class);
    });

    test('the job retries with backoff', function () {
        $job = new SendBookingConfirmedMailJob(pendingFor(10));

        expect($job->tries)->toBe(3)
            ->and($job->backoff)->toBe([10, 60])
            ->and($job->queue)->toBe('mail')
            ->and($job->afterCommit)->toBeTrue();
    });
});

describe('after commit', function () {
    test('inside a transaction that rolls back, nothing is queued and the booking stays pending', function () {
        Queue::fake();
        $booking = pendingFor(10);

        try {
            DB::transaction(function () use ($booking): void {
                $this->service->confirm($booking);

                throw new RuntimeException('Fallo después de confirmar');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNothingPushed();
        expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    });

    test('the event itself waits for the commit and is discarded on rollback', function () {
        Queue::fake();
        $heard = 0;
        Event::listen(BookingConfirmed::class, function () use (&$heard): void {
            $heard++;
        });

        DB::transaction(function () use (&$heard): void {
            $this->service->confirm(pendingFor(10));

            expect($heard)->toBe(0);
        });

        expect($heard)->toBe(1);

        try {
            DB::transaction(function (): void {
                $this->service->confirm(pendingFor(10));

                throw new RuntimeException('Fallo después de confirmar');
            });
        } catch (RuntimeException) {
        }

        expect($heard)->toBe(1);
    });

    test('inside a transaction, the job is queued only when it commits', function () {
        Queue::fake();
        $booking = pendingFor(10);

        DB::transaction(function () use ($booking): void {
            $this->service->confirm($booking);

            Queue::assertNothingPushed();
        });

        Queue::assertPushedTimes(SendBookingConfirmedMailJob::class, 1);
    });
});
