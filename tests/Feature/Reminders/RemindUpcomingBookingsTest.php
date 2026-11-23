<?php

use App\Enums\BookingStatus;
use App\Events\BookingStartingSoon;
use App\Jobs\SendBookingReminderMailJob;
use App\Listeners\SendBookingReminderMail;
use App\Mail\BookingReminderMail;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->reminders = app(ReminderService::class);
    $space = Space::factory()->create(['name' => 'Planta Baja']);
    $this->resource = Resource::factory()->for($space)->create(['name' => 'Sala Azul', 'hourly_rate' => 12]);
});

/**
 * Reserva con una franja de dos horas que empieza en $start (y otras franjas opcionales).
 *
 * @param  list<string>  $otherStarts
 * @param  array<string, mixed>  $attributes
 */
function remindableBooking(string $start, string $state = 'confirmed', array $otherStarts = [], array $attributes = []): Booking
{
    $factory = Booking::factory()->{$state}();

    foreach ([$start, ...$otherStarts] as $slotStart) {
        $startAt = Carbon::parse($slotStart);
        $factory = $factory->withSlot(test()->resource, $startAt, $startAt->copy()->addHours(2));
    }

    return $factory->create(['date' => Carbon::parse($start)->toDateString(), 'total' => '24.00', ...$attributes]);
}

/**
 * @return list<int>
 */
function remindedIds(): array
{
    return Booking::query()->whereNotNull('reminder_sent_at')->orderBy('id')->pluck('id')->all();
}

/**
 * Ejecuta $change una sola vez, justo después de la primera lectura (los ids de las candidatas).
 */
function afterFirstReminderRead(Closure $change): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use ($change, &$done): void {
        if (! $done && str_starts_with($query->sql, 'select')) {
            $done = true;
            $change();
        }
    });
}

describe('window', function () {
    test('reminds bookings whose first slot starts in (now, now + 24 h]', function () {
        Mail::fake();
        $soon = remindableBooking('2026-12-01 09:30');
        $edge = remindableBooking('2026-12-02 09:00');
        $now = remindableBooking('2026-12-01 09:00');
        $later = remindableBooking('2026-12-02 09:30');

        $this->artisan('bookings:remind-upcoming')
            ->expectsOutput('Recordatorios enviados: 2.')
            ->assertExitCode(0);

        expect(remindedIds())->toBe([$soon->id, $edge->id])
            ->and($now->fresh()->reminder_sent_at)->toBeNull()
            ->and($later->fresh()->reminder_sent_at)->toBeNull();
    });

    test('a booking with one slot already started is not reminded', function () {
        Mail::fake();
        remindableBooking('2026-12-01 12:00', otherStarts: ['2026-12-01 08:00']);

        expect($this->reminders->remindUpcoming(now(), 24))->toBe(0);
    });

    test('a booking with several slots counts by its first one', function () {
        Mail::fake();
        $booking = remindableBooking('2026-12-03 10:00', otherStarts: ['2026-12-01 18:00']);

        expect($this->reminders->remindUpcoming(now(), 24))->toBe(1)
            ->and($booking->fresh()->reminder_sent_at?->toDateTimeString())->toBe('2026-12-01 09:00:00');
    });

    test('--hours changes the window', function () {
        Mail::fake();
        $inTwoHours = remindableBooking('2026-12-01 11:00');
        remindableBooking('2026-12-01 11:30');

        $this->artisan('bookings:remind-upcoming', ['--hours' => '2'])
            ->expectsOutput('Recordatorios enviados: 1.')
            ->assertExitCode(0);

        expect(remindedIds())->toBe([$inTwoHours->id]);
    });

    test('only confirmed bookings that are not soft deleted are reminded', function () {
        Mail::fake();
        remindableBooking('2026-12-01 12:00', 'cancelled');
        remindableBooking('2026-12-01 12:00', 'expired');
        Booking::factory()->withSlot($this->resource, Carbon::parse('2026-12-01 14:00'), Carbon::parse('2026-12-01 15:00'))->create();
        remindableBooking('2026-12-01 16:00', attributes: ['status' => BookingStatus::Completed]);
        remindableBooking('2026-12-01 17:00')->delete();

        expect($this->reminders->remindUpcoming(now(), 24))->toBe(0)
            ->and(Booking::withTrashed()->whereNotNull('reminder_sent_at')->count())->toBe(0);
    });
});

describe('marking', function () {
    test('marks the booking and a second run reminds nothing', function () {
        Event::fake([BookingStartingSoon::class]);
        $booking = remindableBooking('2026-12-01 15:00');

        expect($this->reminders->remindUpcoming(now(), 24))->toBe(1)
            ->and($this->reminders->remindUpcoming(now(), 24))->toBe(0)
            ->and($booking->fresh()->reminder_sent_at?->toDateTimeString())->toBe('2026-12-01 09:00:00')
            ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

        Event::assertDispatchedTimes(BookingStartingSoon::class, 1);
    });

    test('the dry run only counts: it marks and dispatches nothing', function () {
        Event::fake([BookingStartingSoon::class]);
        $booking = remindableBooking('2026-12-01 15:00');

        $this->artisan('bookings:remind-upcoming', ['--dry-run' => true])
            ->expectsOutput('Simulación: reservas que se recordarían en las próximas 24 horas: 1. No se ha enviado nada.')
            ->assertExitCode(0);

        expect($booking->fresh()->reminder_sent_at)->toBeNull();
        Event::assertNotDispatched(BookingStartingSoon::class);
    });

    test('if another run marks a booking between the read and the update, it is reminded once', function () {
        Event::fake([BookingStartingSoon::class]);
        $taken = remindableBooking('2026-12-01 12:00');
        $free = remindableBooking('2026-12-01 14:00');

        afterFirstReminderRead(fn () => Booking::query()->whereKey($taken->id)->update(['reminder_sent_at' => now()->subSecond()]));

        expect($this->reminders->remindUpcoming(now(), 24))->toBe(1);

        Event::assertDispatchedTimes(BookingStartingSoon::class, 1);
        Event::assertDispatched(BookingStartingSoon::class, fn (BookingStartingSoon $event) => $event->booking->is($free));
        expect($taken->fresh()->reminder_sent_at?->toDateTimeString())->toBe('2026-12-01 08:59:59');
    });

    test('inside a transaction that rolls back, nothing is dispatched or marked', function () {
        $heard = 0;
        Event::listen(BookingStartingSoon::class, function () use (&$heard): void {
            $heard++;
        });
        $booking = remindableBooking('2026-12-01 15:00');

        try {
            DB::transaction(function (): void {
                $this->reminders->remindUpcoming(now(), 24);

                throw new RuntimeException('Fallo después de marcar');
            });
        } catch (RuntimeException) {
        }

        expect($heard)->toBe(0)
            ->and($booking->fresh()->reminder_sent_at)->toBeNull();
    });
});

describe('options', function () {
    test('rejects an invalid --hours with exit code 1', function (string $hours) {
        Mail::fake();
        $booking = remindableBooking('2026-12-01 15:00');

        $this->artisan('bookings:remind-upcoming', ['--hours' => $hours])
            ->expectsOutput("Valor no válido para --hours: \"{$hours}\". Debe ser un número entero entre 1 y 168.")
            ->assertExitCode(1);

        expect($booking->fresh()->reminder_sent_at)->toBeNull();
        Mail::assertNothingSent();
    })->with(['0', '169', 'abc', '2.5', '-3', '']);

    test('accepts the limits 1 and 168', function (string $hours) {
        $this->artisan('bookings:remind-upcoming', ['--hours' => $hours, '--dry-run' => true])->assertExitCode(0);
    })->with(['1', '168']);
});

describe('event, synchronous job and mail', function () {
    test('the event is listened by SendBookingReminderMail', function () {
        Event::fake();

        Event::assertListening(BookingStartingSoon::class, SendBookingReminderMail::class);
    });

    test('the job runs on the sync connection, not on a real queue', function () {
        Queue::fake();
        $booking = remindableBooking('2026-12-01 15:00');

        $this->reminders->remindUpcoming(now(), 24);

        Queue::assertPushedTimes(SendBookingReminderMailJob::class, 1);
        Queue::assertPushed(SendBookingReminderMailJob::class, fn (SendBookingReminderMailJob $job) => $job->connection === 'sync'
            && $job->booking->is($booking));
    });

    test('the mail is sent right away to the owner, even when the default queue is database', function () {
        config(['queue.default' => 'database']);
        Mail::fake();
        $owner = User::factory()->create(['name' => 'Ana Pérez']);
        $booking = remindableBooking('2026-12-01 15:00', attributes: ['user_id' => $owner->id]);

        $this->reminders->remindUpcoming(now(), 24);

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        Mail::assertSentCount(1);
        Mail::assertSent(BookingReminderMail::class, function (BookingReminderMail $mail) use ($owner, $booking): bool {
            $html = $mail->render();

            return $mail->hasTo($owner->email)
                && $mail->hasSubject('Recordatorio: tu reserva empieza el 01/12/2026 a las 15:00')
                && $mail->booking->is($booking)
                && str_contains($html, 'Ana Pérez')
                && str_contains($html, 'empieza en 6 horas')
                && str_contains($html, 'Sala Azul')
                && str_contains($html, 'Planta Baja')
                && str_contains($html, '01/12/2026 15:00 – 17:00')
                && str_contains($html, '24,00 €');
        });
    });

    test('says "1 hora" when it starts in less than an hour', function () {
        Mail::fake();
        remindableBooking('2026-12-01 09:30');

        $this->reminders->remindUpcoming(now(), 24);

        Mail::assertSent(BookingReminderMail::class, fn (BookingReminderMail $mail) => str_contains($mail->render(), 'empieza en 1 hora,'));
    });

    test('the job sends nothing if the booking is no longer confirmed', function () {
        Mail::fake();
        $booking = remindableBooking('2026-12-01 15:00');
        $booking->update(['status' => BookingStatus::Cancelled]);

        (new SendBookingReminderMailJob($booking->fresh()))->handle();

        Mail::assertNothingSent();
    });

    test('a soft deleted owner gets no mail and does not stop the others', function () {
        Mail::fake();
        $gone = User::factory()->create();
        $stays = User::factory()->create();
        remindableBooking('2026-12-01 12:00', attributes: ['user_id' => $gone->id]);
        remindableBooking('2026-12-01 14:00', attributes: ['user_id' => $stays->id]);
        $gone->delete();

        expect($this->reminders->remindUpcoming(now(), 24))->toBe(2);

        Mail::assertSentCount(1);
        Mail::assertSent(BookingReminderMail::class, fn (BookingReminderMail $mail) => $mail->hasTo($stays->email));
    });

    test('a mail failure is reported and does not stop reminding the rest', function () {
        Exceptions::fake();
        Log::spy();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));
        collect(['12:00', '14:00', '16:00'])->each(fn (string $time) => remindableBooking("2026-12-01 {$time}"));

        expect($this->reminders->remindUpcoming(now(), 24))->toBe(3)
            ->and(remindedIds())->toHaveCount(3);

        Exceptions::assertReportedCount(3);
        Log::shouldHaveReceived('error')->times(3)->with('No se pudo enviar el recordatorio de reserva.', Mockery::type('array'));
    });

    test('the job keeps the common shape but has no queue', function () {
        $job = new SendBookingReminderMailJob(remindableBooking('2026-12-01 15:00'));

        expect($job->tries)->toBe(3)
            ->and($job->backoff)->toBe([10, 60])
            ->and($job->queue)->toBeNull()
            ->and($job->afterCommit)->toBeTrue();
    });

    test('the job logs the failure', function () {
        $booking = remindableBooking('2026-12-01 15:00');
        Log::shouldReceive('error')
            ->once()
            ->with('No se pudo enviar el recordatorio de reserva.', ['booking_id' => $booking->id, 'error' => 'SMTP caído']);

        (new SendBookingReminderMailJob($booking))->failed(new RuntimeException('SMTP caído'));
    });
});
