<?php

use App\Contracts\MessageChannel;
use App\Enums\BookingStatus;
use App\Events\BookingConfirmed;
use App\Exceptions\BookingNotConfirmableException;
use App\Jobs\SendBookingConfirmedMailJob;
use App\Jobs\SendBookingConfirmedTelegramJob;
use App\Listeners\SendBookingConfirmedMail;
use App\Listeners\SendBookingConfirmedTelegram;
use App\Mail\BookingConfirmedMail;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Space;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Notifications\PermanentDeliveryException;
use App\Services\Notifications\TransientDeliveryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Canal falso que guarda lo enviado o lanza la excepción indicada.
 */
class RecordingChannel implements MessageChannel
{
    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    public function __construct(public ?Throwable $failWith = null) {}

    public function send(string $recipient, string $message): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->sent[] = [$recipient, $message];
    }
}

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->service = app(BookingService::class);
    $this->owner = User::factory()->create(['email' => 'ana@workspace.test']);
    $this->owner->forceFill(['telegram_chat_id' => '100000001'])->save();
    $space = Space::factory()->create(['name' => 'Planta Baja']);
    $this->resource = Resource::factory()->for($space)->create(['name' => 'Sala Azul', 'hourly_rate' => 12]);
});

function pendingOf(User $owner): Booking
{
    return Booking::factory()->for($owner, 'user')
        ->withSlot(test()->resource, Carbon::parse('2026-12-02 16:00'), Carbon::parse('2026-12-02 18:00'))
        ->create(['expires_at' => now()->addMinutes(10)]);
}

function useChannel(?Throwable $failWith = null): RecordingChannel
{
    $channel = new RecordingChannel($failWith);
    app()->instance(MessageChannel::class, $channel);

    return $channel;
}

describe('listener', function () {
    test('BookingConfirmed has the mail listener and the Telegram one', function () {
        Event::fake();

        Event::assertListening(BookingConfirmed::class, SendBookingConfirmedMail::class);
        Event::assertListening(BookingConfirmed::class, SendBookingConfirmedTelegram::class);
    });

    test('queues the Telegram job on the mail queue only for a user with Telegram', function () {
        Queue::fake();
        $booking = pendingOf($this->owner);

        $this->service->confirm($booking);

        Queue::assertPushedTimes(SendBookingConfirmedTelegramJob::class, 1);
        Queue::assertPushedOn('mail', SendBookingConfirmedTelegramJob::class, fn (SendBookingConfirmedTelegramJob $job) => $job->booking->is($booking));
        Queue::assertPushedTimes(SendBookingConfirmedMailJob::class, 1);
    });

    test('a user without Telegram gets the mail but no Telegram job', function () {
        Queue::fake();

        $this->service->confirm(pendingOf(User::factory()->create()));

        Queue::assertNotPushed(SendBookingConfirmedTelegramJob::class);
        Queue::assertPushedTimes(SendBookingConfirmedMailJob::class, 1);
    });

    test('a failed confirmation queues nothing', function () {
        Queue::fake();

        try {
            $this->service->confirm(Booking::factory()->for($this->owner, 'user')->cancelled()->create());
        } catch (BookingNotConfirmableException) {
        }

        Queue::assertNothingPushed();
    });

    test('inside a transaction that rolls back, no Telegram job is queued', function () {
        Queue::fake();
        $booking = pendingOf($this->owner);

        try {
            DB::transaction(function () use ($booking): void {
                $this->service->confirm($booking);

                throw new RuntimeException('Fallo después de confirmar');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNothingPushed();
    });

    test('the job retries with backoff on the mail queue after the commit', function () {
        $job = new SendBookingConfirmedTelegramJob(pendingOf($this->owner));

        expect($job->tries)->toBe(3)
            ->and($job->backoff)->toBe([10, 60])
            ->and($job->queue)->toBe('mail')
            ->and($job->afterCommit)->toBeTrue();
    });
});

describe('job', function () {
    test('sends a short plain text with the resources, the slot and the total, without the email', function () {
        $channel = useChannel();
        Queue::fake();
        $booking = $this->service->confirm(pendingOf($this->owner));

        (new SendBookingConfirmedTelegramJob($booking))->handle($channel);

        expect($channel->sent)->toHaveCount(1)
            ->and($channel->sent[0][0])->toBe('100000001')
            ->and($channel->sent[0][1])->toBe(implode("\n", [
                config('app.name').': reserva confirmada.',
                '- Sala Azul (Planta Baja): 02/12/2026, 16:00–18:00',
                'Total: 24,00 €',
            ]))
            ->and($channel->sent[0][1])->not->toContain('ana@workspace.test');
    });

    test('sends nothing if the booking is no longer confirmed, the user is gone or turned it off', function (Closure $change) {
        $channel = useChannel();
        Queue::fake();
        $booking = $this->service->confirm(pendingOf($this->owner));
        $change($booking, $this->owner);

        (new SendBookingConfirmedTelegramJob($booking->fresh()))->handle($channel);

        expect($channel->sent)->toBe([]);
    })->with([
        'ya no confirmada' => [fn (Booking $booking) => $booking->update(['status' => BookingStatus::Cancelled])],
        'usuario borrado' => [fn (Booking $booking, User $owner) => $owner->delete()],
        'Telegram desactivado' => [fn (Booking $booking, User $owner) => $owner->forceFill(['telegram_chat_id' => null])->save()],
    ]);

    test('a permanent error fails the job at once, without retrying', function () {
        $channel = useChannel(new PermanentDeliveryException('Telegram rechazó el mensaje (HTTP 403).'));
        Queue::fake();
        $job = (new SendBookingConfirmedTelegramJob($this->service->confirm(pendingOf($this->owner))))->withFakeQueueInteractions();

        $job->handle($channel);

        $job->assertFailed();
        $job->assertNotReleased();
    });

    test('a transient error is thrown again so the queue retries it', function () {
        $channel = useChannel(new TransientDeliveryException('Telegram no aceptó el mensaje ahora (HTTP 503).'));
        Queue::fake();
        $job = (new SendBookingConfirmedTelegramJob($this->service->confirm(pendingOf($this->owner))))->withFakeQueueInteractions();

        expect(fn () => $job->handle($channel))->toThrow(TransientDeliveryException::class);
        $job->assertNotFailed();
    });

    test('failed() logs the booking and the error, never the chat id', function () {
        $booking = pendingOf($this->owner);
        Log::shouldReceive('error')->once()->with('No se pudo enviar el aviso por Telegram de la reserva confirmada.', [
            'booking_id' => $booking->id,
            'error' => PermanentDeliveryException::class.': Telegram rechazó el mensaje (HTTP 403).',
        ]);

        (new SendBookingConfirmedTelegramJob($booking))->failed(new PermanentDeliveryException('Telegram rechazó el mensaje (HTTP 403).'));
    });
});

describe('isolation from the mail', function () {
    test('with the sync queue, a Telegram failure does not break the confirmation or the mail', function (Throwable $error) {
        useChannel($error);
        Mail::fake();
        Exceptions::fake();
        Log::spy();
        $booking = pendingOf($this->owner);

        $confirmed = $this->service->confirm($booking);

        expect($confirmed->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
        Mail::assertSent(BookingConfirmedMail::class, fn (BookingConfirmedMail $mail) => $mail->hasTo('ana@workspace.test'));
    })->with([
        'permanente' => [new PermanentDeliveryException('Telegram rechazó el mensaje (HTTP 403).')],
        'transitorio' => [new TransientDeliveryException('Telegram no aceptó el mensaje ahora (HTTP 503).')],
    ]);

    test('with the database queue, a transient failure leaves the job to retry and the mail goes out', function () {
        config(['queue.default' => 'database']);
        useChannel(new TransientDeliveryException('Telegram no aceptó el mensaje ahora (HTTP 503).'));
        Mail::fake();
        Exceptions::fake();

        $this->service->confirm(pendingOf($this->owner));
        expect(DB::table('jobs')->where('queue', 'mail')->count())->toBe(2);

        Artisan::call('queue:work', ['--queue' => 'mail', '--stop-when-empty' => true]);

        Mail::assertSentCount(1);
        // El de Telegram vuelve a la cola para dentro de 10 s (backoff); nada en failed_jobs.
        expect(DB::table('jobs')->where('payload', 'like', '%SendBookingConfirmedTelegramJob%')->count())->toBe(1)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });
});

test('full flow with the database queue and the simulated channel: the worker sends the mail and the message', function () {
    config([
        'queue.default' => 'database',
        'logging.channels.notifications.path' => $logPath = sys_get_temp_dir().'/workspace-notifications-'.uniqid().'.log',
    ]);
    Mail::fake();

    $this->service->confirm(pendingOf($this->owner));

    expect(DB::table('jobs')->where('queue', 'mail')->count())->toBe(2);

    Artisan::call('queue:work', ['--queue' => 'mail', '--stop-when-empty' => true]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    Mail::assertSent(BookingConfirmedMail::class);
    Http::assertNothingSent();

    $log = (string) file_get_contents($logPath);
    @unlink($logPath);

    expect($log)->toContain('Mensaje simulado')
        ->toContain('*****0001')
        ->toContain('reserva confirmada.')
        ->toContain('Sala Azul (Planta Baja): 02/12/2026, 16:00–18:00')
        ->toContain('Total: 24,00 €')
        ->not->toContain('100000001');
});
