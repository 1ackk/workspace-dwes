<?php

use App\Enums\BookingStatus;
use App\Events\BookingCancelled;
use App\Exceptions\BookingNotCancellableException;
use App\Jobs\SendBookingCancelledMailJob;
use App\Listeners\SendBookingCancelledMail;
use App\Mail\BookingCancelledMail;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->service = app(BookingService::class);
    $this->owner = User::factory()->create(['name' => 'Ana Pérez']);

    $space = Space::factory()->create(['name' => 'Planta Baja', 'cancellation_notice_hours' => 24]);
    $this->resource = Resource::factory()->for($space)->create(['name' => 'Sala Azul', 'hourly_rate' => 12]);
});

/**
 * Reserva confirmada de Ana en Sala Azul que empieza dentro de $hours horas y dura dos.
 */
function cancellableIn(int $hours, string $factoryState = 'confirmed'): Booking
{
    $start = now()->addHours($hours);

    return Booking::factory()->{$factoryState}()
        ->for(test()->owner, 'user')
        ->withSlot(test()->resource, $start, $start->copy()->addHours(2))
        ->create(['date' => $start->toDateString(), 'total' => '24.00']);
}

/**
 * Llama a cancel y se traga el error de dominio esperado.
 */
function tryToCancel(Booking $booking): void
{
    try {
        app(BookingService::class)->cancel($booking);
    } catch (BookingNotCancellableException) {
    }
}

describe('event', function () {
    test('a cancellation within the deadline dispatches BookingCancelled once', function () {
        Event::fake([BookingCancelled::class]);
        $booking = cancellableIn(48);

        $this->service->cancel($booking);

        Event::assertDispatchedTimes(BookingCancelled::class, 1);
        Event::assertDispatched(BookingCancelled::class, fn (BookingCancelled $event) => $event->booking->is($booking)
            && $event->booking->status === BookingStatus::Cancelled);
    });

    test('a cancellation that fails dispatches nothing', function (Closure $makeBooking) {
        Event::fake([BookingCancelled::class]);

        tryToCancel($makeBooking());

        Event::assertNotDispatched(BookingCancelled::class);
    })->with([
        'fuera de plazo' => [fn () => cancellableIn(10)],
        'ya cancelada' => [fn () => cancellableIn(48, 'cancelled')],
        'caducada' => [fn () => cancellableIn(48, 'expired')],
        'completada' => [fn () => tap(cancellableIn(48))->update(['status' => BookingStatus::Completed])],
    ]);

    test('cancelling twice only notifies the first time', function () {
        Event::fake([BookingCancelled::class]);
        $booking = cancellableIn(48);

        $this->service->cancel($booking);
        tryToCancel($booking);

        Event::assertDispatchedTimes(BookingCancelled::class, 1);
    });

    test('the event is listened by SendBookingCancelledMail', function () {
        Event::fake();

        Event::assertListening(BookingCancelled::class, SendBookingCancelledMail::class);
    });
});

describe('queue', function () {
    test('the listener queues exactly one mail job on the mail queue', function () {
        Queue::fake();
        $booking = cancellableIn(48);

        $this->service->cancel($booking);

        Queue::assertPushedTimes(SendBookingCancelledMailJob::class, 1);
        Queue::assertPushedOn('mail', SendBookingCancelledMailJob::class, fn (SendBookingCancelledMailJob $job) => $job->booking->is($booking));
    });

    test('nothing is queued when the cancellation fails', function () {
        Queue::fake();

        tryToCancel(cancellableIn(10));

        Queue::assertNothingPushed();
    });

    test('the job retries with backoff after the commit', function () {
        $job = new SendBookingCancelledMailJob(cancellableIn(48));

        expect($job->tries)->toBe(3)
            ->and($job->backoff)->toBe([10, 60])
            ->and($job->queue)->toBe('mail')
            ->and($job->afterCommit)->toBeTrue();
    });
});

describe('after commit', function () {
    test('inside a transaction that rolls back, nothing is dispatched or queued', function () {
        Queue::fake();
        $heard = 0;
        Event::listen(BookingCancelled::class, function () use (&$heard): void {
            $heard++;
        });
        $booking = cancellableIn(48);

        try {
            DB::transaction(function () use ($booking): void {
                $this->service->cancel($booking);

                throw new RuntimeException('Fallo después de cancelar');
            });
        } catch (RuntimeException) {
        }

        expect($heard)->toBe(0)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
        Queue::assertNothingPushed();
    });
});

describe('mail', function () {
    test('full flow: the worker processes the job and the owner gets the mail', function () {
        config(['queue.default' => 'database']);
        Mail::fake();
        $booking = cancellableIn(48);

        $this->service->cancel($booking);

        Mail::assertNothingSent();
        expect(DB::table('jobs')->where('queue', 'mail')->count())->toBe(1);

        Artisan::call('queue:work', ['--queue' => 'mail', '--stop-when-empty' => true]);

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        Mail::assertSentCount(1);
        Mail::assertSent(BookingCancelledMail::class, function (BookingCancelledMail $mail) use ($booking): bool {
            $html = $mail->render();

            return $mail->hasTo($this->owner->email)
                && $mail->hasSubject('Reserva cancelada del 03/12/2026')
                && $mail->booking->is($booking)
                && str_contains($html, 'Ana Pérez')
                && str_contains($html, 'Sala Azul')
                && str_contains($html, 'Planta Baja')
                && str_contains($html, '03/12/2026 09:00 – 11:00')
                && str_contains($html, '24,00 €');
        });
    });

    test('the job sends nothing if the booking is no longer cancelled', function () {
        Queue::fake();
        Mail::fake();
        $booking = cancellableIn(48);
        $this->service->cancel($booking);
        Booking::query()->whereKey($booking->id)->update(['status' => BookingStatus::Confirmed]);

        (new SendBookingCancelledMailJob($booking->fresh()))->handle();

        Mail::assertNothingSent();
    });

    test('the job sends nothing if the owner was soft deleted', function () {
        Queue::fake();
        Mail::fake();
        $booking = cancellableIn(48);
        $this->service->cancel($booking);
        $this->owner->delete();

        (new SendBookingCancelledMailJob($booking->fresh()))->handle();

        Mail::assertNothingSent();
    });

    test('the job logs the failure once the attempts are exhausted', function () {
        $booking = cancellableIn(48);
        Log::shouldReceive('error')
            ->once()
            ->with('No se pudo enviar el correo de reserva cancelada.', ['booking_id' => $booking->id, 'error' => 'SMTP caído']);

        (new SendBookingCancelledMailJob($booking))->failed(new RuntimeException('SMTP caído'));
    });
});
