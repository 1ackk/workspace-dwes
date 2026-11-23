<?php

use App\Enums\BookingStatus;
use App\Enums\RoleName;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingExpired;
use App\Jobs\SendBookingExpiredMailJob;
use App\Listeners\SendBookingExpiredMail;
use App\Mail\BookingExpiredMail;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use App\Models\User;
use App\Services\Booking\BookingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->service = app(BookingService::class);
    $space = Space::factory()->create(['name' => 'Planta Alta']);
    $this->resource = Resource::factory()->for($space)->create(['name' => 'Puesto 3', 'hourly_rate' => 5]);
});

/**
 * Reserva pendiente cuya retención venció hace un minuto, con una franja de dos horas el 05/12/2026.
 */
function expiredHold(?User $owner = null, int $hour = 10): Booking
{
    $start = Carbon::parse('2026-12-05')->setTime($hour, 0);

    return Booking::factory()
        ->for($owner ?? User::factory()->create(), 'user')
        ->withSlot(test()->resource, $start, $start->copy()->addHours(2))
        ->create(['date' => '2026-12-05', 'expires_at' => now()->subMinute(), 'total' => '10.00']);
}

/**
 * Ejecuta $change una sola vez, justo después de que releaseUnpaid lea los ids de las candidatas.
 */
function afterReadingCandidates(Closure $change): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use ($change, &$done): void {
        if (! $done && str_starts_with($query->sql, 'select')) {
            $done = true;
            $change();
        }
    });
}

describe('event', function () {
    test('dispatches one BookingExpired per booking that really changed', function () {
        Event::fake([BookingExpired::class]);
        $first = expiredHold(hour: 10);
        $confirmedMeanwhile = expiredHold(hour: 12);
        $third = expiredHold(hour: 14);

        // Otra petición cambia una candidata justo después de leer los ids: el UPDATE condicional
        // ya no la encuentra pendiente, no la pisa y no debe notificarla.
        afterReadingCandidates(fn () => Booking::query()->whereKey($confirmedMeanwhile->id)->update(['status' => BookingStatus::Confirmed]));

        expect($this->service->releaseUnpaid(now()))->toBe(2);

        Event::assertDispatchedTimes(BookingExpired::class, 2);
        Event::assertDispatched(BookingExpired::class, fn (BookingExpired $event) => $event->booking->is($first)
            && $event->booking->status === BookingStatus::Expired);
        Event::assertDispatched(BookingExpired::class, fn (BookingExpired $event) => $event->booking->is($third));
        Event::assertNotDispatched(BookingExpired::class, fn (BookingExpired $event) => $event->booking->is($confirmedMeanwhile));
    });

    test('a second run dispatches nothing', function () {
        Event::fake([BookingExpired::class]);
        expiredHold();

        $this->service->releaseUnpaid(now());
        $this->service->releaseUnpaid(now());

        Event::assertDispatchedTimes(BookingExpired::class, 1);
    });

    test('holds still in force and other states dispatch nothing', function () {
        Event::fake([BookingExpired::class]);
        Booking::factory()->create(['expires_at' => now()->addMinute()]);
        Booking::factory()->confirmed()->create();
        Booking::factory()->cancelled()->create(['expires_at' => now()->subHour()]);

        expect($this->service->releaseUnpaid(now()))->toBe(0);

        Event::assertNotDispatched(BookingExpired::class);
    });

    test('the dry run of the command dispatches nothing', function () {
        Event::fake([BookingExpired::class]);
        $booking = expiredHold();

        $this->artisan('bookings:release-unpaid', ['--dry-run' => true])
            ->expectsOutput('Simulación: reservas que se liberarían: 1. No se ha cambiado nada.')
            ->assertExitCode(0);

        Event::assertNotDispatched(BookingExpired::class);
        expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    });

    test('completing past bookings dispatches no booking event', function () {
        $bookingEvents = [BookingConfirmed::class, BookingCancelled::class, BookingExpired::class];
        Event::fake($bookingEvents);
        $end = now()->subHour();
        Booking::factory()->confirmed()->withSlot($this->resource, $end->copy()->subHour(), $end)->create();

        expect($this->service->completePast(now()))->toBe(1);

        foreach ($bookingEvents as $event) {
            Event::assertNotDispatched($event);
        }
    });

    test('the event is listened by SendBookingExpiredMail', function () {
        Event::fake();

        Event::assertListening(BookingExpired::class, SendBookingExpiredMail::class);
    });

    test('inside a transaction that rolls back, nothing is dispatched', function () {
        $heard = 0;
        Event::listen(BookingExpired::class, function () use (&$heard): void {
            $heard++;
        });
        $booking = expiredHold();

        try {
            DB::transaction(function (): void {
                $this->service->releaseUnpaid(now());

                throw new RuntimeException('Fallo después de liberar');
            });
        } catch (RuntimeException) {
        }

        expect($heard)->toBe(0)
            ->and($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    });
});

describe('synchronous job and mail', function () {
    test('the mail is sent right away even when the default queue is database', function () {
        config(['queue.default' => 'database']);
        Mail::fake();
        $owner = User::factory()->create(['name' => 'Luis Gómez']);
        $booking = expiredHold($owner);

        $this->service->releaseUnpaid(now());

        // Síncrono: no pasa por la tabla jobs y el correo ya ha salido al terminar releaseUnpaid.
        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        Mail::assertSentCount(1);
        Mail::assertSent(BookingExpiredMail::class, function (BookingExpiredMail $mail) use ($owner, $booking): bool {
            $html = $mail->render();

            return $mail->hasTo($owner->email)
                && $mail->hasSubject('Tu reserva del 05/12/2026 ha caducado')
                && $mail->booking->is($booking)
                && str_contains($html, 'Luis Gómez')
                && str_contains($html, 'Puesto 3')
                && str_contains($html, 'Planta Alta')
                && str_contains($html, '05/12/2026 10:00 – 12:00')
                && str_contains($html, '10,00 €')
                && str_contains($html, 'han quedado libres');
        });
    });

    test('the listener runs the job on the sync connection, not on a real queue', function () {
        Queue::fake();
        $booking = expiredHold();

        $this->service->releaseUnpaid(now());

        Queue::assertPushedTimes(SendBookingExpiredMailJob::class, 1);
        Queue::assertPushed(SendBookingExpiredMailJob::class, fn (SendBookingExpiredMailJob $job) => $job->connection === 'sync'
            && $job->booking->is($booking));
    });

    test('a soft deleted owner gets no mail and does not stop the others', function () {
        Mail::fake();
        $gone = User::factory()->create();
        $stays = User::factory()->create();
        $goneBooking = expiredHold($gone, 10);
        $staysBooking = expiredHold($stays, 12);
        $gone->delete();

        expect($this->service->releaseUnpaid(now()))->toBe(2)
            ->and($goneBooking->fresh()->status)->toBe(BookingStatus::Expired)
            ->and($staysBooking->fresh()->status)->toBe(BookingStatus::Expired);

        Mail::assertSentCount(1);
        Mail::assertSent(BookingExpiredMail::class, fn (BookingExpiredMail $mail) => $mail->hasTo($stays->email));
    });

    test('a mail failure is reported and does not stop releasing the rest', function () {
        Exceptions::fake();
        Log::spy();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));
        $bookings = collect([10, 12, 14])->map(fn (int $hour) => expiredHold(hour: $hour));

        expect($this->service->releaseUnpaid(now()))->toBe(3);

        $bookings->each(fn (Booking $booking) => expect($booking->fresh()->status)->toBe(BookingStatus::Expired));
        Exceptions::assertReportedCount(3);
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'SMTP caído');
        Log::shouldHaveReceived('error')->times(3)->with('No se pudo enviar el correo de reserva caducada.', Mockery::type('array'));
    });

    test('the job sends nothing if the booking is no longer expired', function () {
        Mail::fake();
        $booking = expiredHold();
        $booking->update(['status' => BookingStatus::Confirmed]);

        (new SendBookingExpiredMailJob($booking->fresh()))->handle();

        Mail::assertNothingSent();
    });

    test('the job keeps the common shape but has no queue', function () {
        $job = new SendBookingExpiredMailJob(expiredHold());

        expect($job->tries)->toBe(3)
            ->and($job->backoff)->toBe([10, 60])
            ->and($job->queue)->toBeNull()
            ->and($job->afterCommit)->toBeTrue();
    });
});

describe('command and panel', function () {
    beforeEach(function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RoleSeeder::class);
        Mail::fake();
    });

    test('the command releases, notifies and prints the same message as before', function () {
        expiredHold(hour: 10);
        expiredHold(hour: 12);

        $this->artisan('bookings:release-unpaid')
            ->expectsOutput('Reservas liberadas: 2.')
            ->assertExitCode(0);

        Mail::assertSentCount(2);

        $this->artisan('bookings:release-unpaid')
            ->expectsOutput('Reservas liberadas: 0.')
            ->assertExitCode(0);

        Mail::assertSentCount(2);
    });

    test('the maintenance panel releases and notifies as before', function () {
        expiredHold();

        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('admin.maintenance.run', 'release-unpaid'))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHas('status', 'Reservas liberadas: 1.');

        Mail::assertSentCount(1);
    });
});
