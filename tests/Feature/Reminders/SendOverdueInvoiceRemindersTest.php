<?php

use App\Events\InvoiceOverdue;
use App\Jobs\SendInvoiceOverdueMailJob;
use App\Listeners\SendInvoiceOverdueMail;
use App\Mail\InvoiceOverdueMail;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->reminders = app(ReminderService::class);
});

/**
 * Factura pendiente con una reserva confirmada que suma $total. Por defecto venció hace 10 días.
 *
 * @param  array<string, mixed>  $attributes
 */
function overdueFor(?User $owner = null, string $total = '100.00', string $dueAt = '2026-11-21', array $attributes = []): Invoice
{
    $owner ??= User::factory()->create();
    $invoice = Invoice::factory()->for($owner, 'user')->create(['due_at' => $dueAt, 'amount' => $total, ...$attributes]);
    Booking::factory()->confirmed()->for($owner, 'user')->create(['invoice_id' => $invoice->id, 'total' => $total]);

    return $invoice;
}

function paidPart(Invoice $invoice, string $amount): void
{
    Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => $amount]);
}

/**
 * @return list<int>
 */
function remindedInvoiceIds(): array
{
    return Invoice::query()->whereNotNull('last_reminded_at')->orderBy('id')->pluck('id')->all();
}

/**
 * Ejecuta $change una sola vez, justo después de la primera lectura (los ids de las candidatas).
 */
function afterFirstInvoiceRead(Closure $change): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use ($change, &$done): void {
        if (! $done && str_starts_with($query->sql, 'select')) {
            $done = true;
            $change();
        }
    });
}

describe('which invoices', function () {
    test('reminds pending overdue invoices with balance, also partly paid ones', function () {
        Queue::fake();
        $unpaid = overdueFor();
        $partly = overdueFor();
        paidPart($partly, '40.00');
        $failedPayment = overdueFor();
        Payment::factory()->failed()->create(['invoice_id' => $failedPayment->id, 'amount' => '100.00']);

        $this->artisan('invoices:send-overdue-reminders')
            ->expectsOutput('Avisos de factura vencida enviados: 3.')
            ->assertExitCode(0);

        expect(remindedInvoiceIds())->toBe([$unpaid->id, $partly->id, $failedPayment->id]);
    });

    test('skips invoices without balance, not yet due, paid, void or soft deleted', function () {
        Queue::fake();
        $fullyPaid = overdueFor();
        paidPart($fullyPaid, '100.00');
        overdueFor(dueAt: '2026-12-01');
        overdueFor(attributes: ['status' => 'paid']);
        overdueFor(attributes: ['status' => 'void']);
        overdueFor()->delete();

        expect($this->reminders->remindOverdue(now(), today(), 7))->toBe(0)
            ->and(Invoice::withTrashed()->whereNotNull('last_reminded_at')->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    test('--as-of moves the due date cut', function () {
        Queue::fake();
        $dueOn30 = overdueFor(dueAt: '2026-11-30');
        $dueOn21 = overdueFor(dueAt: '2026-11-21');

        $this->artisan('invoices:send-overdue-reminders', ['--as-of' => '2026-11-30'])
            ->expectsOutput('Avisos de factura vencida enviados: 1.')
            ->assertExitCode(0);

        expect(remindedInvoiceIds())->toBe([$dueOn21->id])
            ->and($dueOn30->fresh()->last_reminded_at)->toBeNull();
    });
});

describe('marking', function () {
    test('marks only last_reminded_at: status, amount and updated_at do not change', function () {
        Queue::fake();
        $invoice = overdueFor();
        $before = $invoice->fresh();
        Carbon::setTestNow('2026-12-01 09:05:00');

        $this->reminders->remindOverdue(now(), today(), 7);

        $after = $invoice->fresh();
        expect($after->last_reminded_at?->toDateTimeString())->toBe('2026-12-01 09:05:00')
            ->and($after->status)->toBe($before->status)
            ->and($after->amount)->toBe($before->amount)
            ->and($after->updated_at?->toDateTimeString())->toBe($before->updated_at?->toDateTimeString());
    });

    test('a second run within the period reminds nothing; after it, it reminds again', function () {
        Event::fake([InvoiceOverdue::class]);
        overdueFor();

        expect($this->reminders->remindOverdue(now(), today(), 7))->toBe(1)
            ->and($this->reminders->remindOverdue(now(), today(), 7))->toBe(0);

        // Justo a los 7 días todavía no: hace falta que el último aviso sea anterior a ahora − 7 días.
        Carbon::setTestNow('2026-12-08 09:00:00');
        expect($this->reminders->remindOverdue(now(), today(), 7))->toBe(0);

        Carbon::setTestNow('2026-12-08 09:00:01');
        expect($this->reminders->remindOverdue(now(), today(), 7))->toBe(1);

        Event::assertDispatchedTimes(InvoiceOverdue::class, 2);
    });

    test('--every-days changes the period', function () {
        Queue::fake();
        $invoice = overdueFor(attributes: ['last_reminded_at' => '2026-11-29 09:00:00']);

        $this->artisan('invoices:send-overdue-reminders')->expectsOutput('Avisos de factura vencida enviados: 0.');
        $this->artisan('invoices:send-overdue-reminders', ['--every-days' => '1'])->expectsOutput('Avisos de factura vencida enviados: 1.');

        expect($invoice->fresh()->last_reminded_at?->toDateTimeString())->toBe('2026-12-01 09:00:00');
    });

    test('the dry run only counts: it marks and dispatches nothing', function () {
        Event::fake([InvoiceOverdue::class]);
        $invoice = overdueFor();

        $this->artisan('invoices:send-overdue-reminders', ['--dry-run' => true])
            ->expectsOutput('Simulación: facturas vencidas que se avisarían a fecha 01/12/2026: 1. No se ha enviado nada.')
            ->assertExitCode(0);

        expect($invoice->fresh()->last_reminded_at)->toBeNull();
        Event::assertNotDispatched(InvoiceOverdue::class);
    });

    test('if another run marks an invoice between the read and the update, it is reminded once', function () {
        Event::fake([InvoiceOverdue::class]);
        $taken = overdueFor();
        $free = overdueFor();

        afterFirstInvoiceRead(fn () => Invoice::query()->whereKey($taken->id)->update(['last_reminded_at' => now()->subSecond()]));

        expect($this->reminders->remindOverdue(now(), today(), 7))->toBe(1);

        Event::assertDispatchedTimes(InvoiceOverdue::class, 1);
        Event::assertDispatched(InvoiceOverdue::class, fn (InvoiceOverdue $event) => $event->invoice->is($free)
            && $event->asOf === '2026-12-01');
    });

    test('inside a transaction that rolls back, nothing is dispatched or marked', function () {
        Queue::fake();
        $invoice = overdueFor();

        try {
            DB::transaction(function (): void {
                $this->reminders->remindOverdue(now(), today(), 7);

                throw new RuntimeException('Fallo después de marcar');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNothingPushed();
        expect($invoice->fresh()->last_reminded_at)->toBeNull();
    });
});

describe('options', function () {
    test('rejects an invalid --every-days with exit code 1', function (string $days) {
        Queue::fake();
        $invoice = overdueFor();

        $this->artisan('invoices:send-overdue-reminders', ['--every-days' => $days])
            ->expectsOutput("Valor no válido para --every-days: \"{$days}\". Debe ser un número entero entre 1 y 90.")
            ->assertExitCode(1);

        expect($invoice->fresh()->last_reminded_at)->toBeNull();
        Queue::assertNothingPushed();
    })->with(['0', '91', 'abc', '1.5', '']);

    test('rejects an invalid --as-of with exit code 1', function (string $date) {
        Queue::fake();
        overdueFor();

        $this->artisan('invoices:send-overdue-reminders', ['--as-of' => $date])
            ->expectsOutput("Fecha no válida: \"{$date}\". Usa el formato AAAA-MM-DD.")
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    })->with(['2026-02-31', '2026-2-3', '01/12/2026', 'mañana', '']);

    test('accepts the limits 1 and 90', function (string $days) {
        $this->artisan('invoices:send-overdue-reminders', ['--every-days' => $days, '--dry-run' => true])->assertExitCode(0);
    })->with(['1', '90']);
});

describe('event, queued job and mail', function () {
    test('the event is listened by SendInvoiceOverdueMail', function () {
        Event::fake();

        Event::assertListening(InvoiceOverdue::class, SendInvoiceOverdueMail::class);
    });

    test('queues exactly one job per reminded invoice on the mail queue', function () {
        Queue::fake();
        $first = overdueFor();
        $second = overdueFor();

        $this->reminders->remindOverdue(now(), today(), 7);

        Queue::assertPushedTimes(SendInvoiceOverdueMailJob::class, 2);
        Queue::assertPushedOn('mail', SendInvoiceOverdueMailJob::class, fn (SendInvoiceOverdueMailJob $job) => $job->invoice->is($first));
        Queue::assertPushedOn('mail', SendInvoiceOverdueMailJob::class, fn (SendInvoiceOverdueMailJob $job) => $job->invoice->is($second));
    });

    test('full flow: the worker processes the job and the owner gets the mail with the balance', function () {
        config(['queue.default' => 'database']);
        Mail::fake();
        $owner = User::factory()->create(['name' => 'Ana Pérez']);
        $invoice = overdueFor($owner, attributes: ['number' => 'FAC-2026-000042']);
        paidPart($invoice, '40.00');

        $this->artisan('invoices:send-overdue-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
        expect(DB::table('jobs')->where('queue', 'mail')->count())->toBe(1);

        Artisan::call('queue:work', ['--queue' => 'mail', '--stop-when-empty' => true]);

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        Mail::assertSentCount(1);
        Mail::assertSent(InvoiceOverdueMail::class, function (InvoiceOverdueMail $mail) use ($owner): bool {
            $html = $mail->render();

            return $mail->hasTo($owner->email)
                && $mail->hasSubject('Factura FAC-2026-000042 vencida: saldo pendiente')
                && str_contains($html, 'Ana Pérez')
                && str_contains($html, 'FAC-2026-000042')
                && str_contains($html, '21/11/2026')
                && $mail->daysLate() === 10
                && preg_match('/>\s*10\s*</', $html) === 1
                && str_contains($html, '60,00 €');
        });
    });

    test('the job takes the balance at the moment it runs', function () {
        Queue::fake();
        Mail::fake();
        $invoice = overdueFor();
        $this->reminders->remindOverdue(now(), today(), 7);
        paidPart($invoice, '70.00');

        (new SendInvoiceOverdueMailJob($invoice->fresh(), '2026-12-01'))->handle();

        Mail::assertSent(InvoiceOverdueMail::class, fn (InvoiceOverdueMail $mail) => $mail->invoice->balance === '30.00');
    });

    test('the job sends nothing if the invoice was paid in the meantime', function () {
        Queue::fake();
        Mail::fake();
        $invoice = overdueFor();
        $this->reminders->remindOverdue(now(), today(), 7);
        paidPart($invoice, '100.00');

        (new SendInvoiceOverdueMailJob($invoice->fresh(), '2026-12-01'))->handle();

        Mail::assertNothingSent();
    });

    test('the job sends nothing if the owner was soft deleted', function () {
        Queue::fake();
        Mail::fake();
        $owner = User::factory()->create();
        $invoice = overdueFor($owner);
        $this->reminders->remindOverdue(now(), today(), 7);
        $owner->delete();

        (new SendInvoiceOverdueMailJob($invoice->fresh(), '2026-12-01'))->handle();

        Mail::assertNothingSent();
    });

    test('a mail failure is reported and does not stop the others', function () {
        // phpunit.xml usa la cola sync: el job se ejecuta al despacharlo y su error sube hasta aquí.
        Exceptions::fake();
        Log::spy();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));
        collect(range(1, 3))->each(fn () => overdueFor());

        expect($this->reminders->remindOverdue(now(), today(), 7))->toBe(3)
            ->and(remindedInvoiceIds())->toHaveCount(3);

        Exceptions::assertReportedCount(3);
        Log::shouldHaveReceived('error')->times(3)->with('No se pudo enviar el aviso de factura vencida.', Mockery::type('array'));
    });

    test('the job retries with backoff on the mail queue after the commit', function () {
        $job = new SendInvoiceOverdueMailJob(overdueFor(), '2026-12-01');

        expect($job->tries)->toBe(3)
            ->and($job->backoff)->toBe([10, 60])
            ->and($job->queue)->toBe('mail')
            ->and($job->afterCommit)->toBeTrue();
    });

    test('the job logs the failure', function () {
        $invoice = overdueFor();
        Log::shouldReceive('error')
            ->once()
            ->with('No se pudo enviar el aviso de factura vencida.', ['invoice_id' => $invoice->id, 'error' => 'SMTP caído']);

        (new SendInvoiceOverdueMailJob($invoice, '2026-12-01'))->failed(new RuntimeException('SMTP caído'));
    });
});
