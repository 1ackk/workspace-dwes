<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');
});

/**
 * Factura pendiente con una reserva confirmada que suma $total. Por defecto venció ayer.
 *
 * @param  array<string, mixed>  $attributes
 */
function billedInvoice(string $total = '100.00', string $dueAt = '2026-11-30', array $attributes = []): Invoice
{
    $invoice = Invoice::factory()->create(['due_at' => $dueAt, ...$attributes]);
    addBooking($invoice, $total);

    return $invoice;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function addBooking(Invoice $invoice, string $total, array $attributes = []): Booking
{
    return Booking::factory()->confirmed()->create([
        'user_id' => $invoice->user_id,
        'invoice_id' => $invoice->id,
        'total' => $total,
        ...$attributes,
    ]);
}

function addPayment(Invoice $invoice, string $amount, PaymentStatus $status = PaymentStatus::Completed): Payment
{
    return Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => $amount, 'status' => $status]);
}

/**
 * @return list<int>
 */
function overdueIds(?CarbonInterface $asOf = null): array
{
    return Invoice::query()->overdueInvoices($asOf)->pluck('id')->all();
}

function balanceOf(Invoice $invoice): string
{
    return Invoice::query()->whereKey($invoice->id)->overdueInvoices()->firstOrFail()->balance;
}

describe('due date', function () {
    test('an invoice due today is not overdue yet', function () {
        billedInvoice(dueAt: '2026-12-01');

        expect(overdueIds())->toBe([]);
    });

    test('an invoice due yesterday without payments is overdue for its whole total', function () {
        $invoice = billedInvoice();

        expect(overdueIds())->toBe([$invoice->id])
            ->and(balanceOf($invoice))->toBe('100.00');
    });

    test('an invoice without a due date is never overdue', function () {
        billedInvoice(attributes: ['due_at' => null]);

        expect(overdueIds())->toBe([]);
    });

    test('an explicit reference date replaces today', function () {
        $invoice = billedInvoice(dueAt: '2026-12-05');

        expect(overdueIds(Carbon::parse('2026-12-05')))->toBe([])
            ->and(overdueIds(Carbon::parse('2026-12-06')))->toBe([$invoice->id])
            ->and(overdueIds())->toBe([]);
    });

    test('the reference date is read in the application timezone', function () {
        $invoice = billedInvoice(dueAt: '2026-12-04');

        // 23:30 UTC del día 4 ya es día 5 en Madrid.
        expect(overdueIds(Carbon::parse('2026-12-04 23:30:00', 'UTC')))->toBe([$invoice->id]);
    });
});

describe('payments', function () {
    test('a partial payment does not release the invoice', function () {
        $invoice = billedInvoice();
        addPayment($invoice, '40.00');

        expect(overdueIds())->toBe([$invoice->id])
            ->and(balanceOf($invoice))->toBe('60.00');
    });

    test('a full payment releases the invoice even if it is still marked as pending', function () {
        addPayment(billedInvoice(), '100.00');

        expect(overdueIds())->toBe([]);
    });

    test('an overpaid invoice is not overdue', function () {
        addPayment(billedInvoice(), '120.00');

        expect(overdueIds())->toBe([]);
    });

    test('a payment that is not completed does not count', function (PaymentStatus $status) {
        $invoice = billedInvoice();
        addPayment($invoice, '100.00', $status);

        expect(overdueIds())->toBe([$invoice->id])
            ->and(balanceOf($invoice))->toBe('100.00');
    })->with([PaymentStatus::Failed, PaymentStatus::Pending, PaymentStatus::Refunded]);

    test('several bookings and payments are added up without multiplying each other', function () {
        $invoice = billedInvoice('100.00');
        addBooking($invoice, '50.00');
        addPayment($invoice, '30.00');
        addPayment($invoice, '20.00');

        expect(balanceOf($invoice))->toBe('100.00');
    });

    test('decimals that do not add up exactly in binary leave no phantom balance', function () {
        $invoice = billedInvoice('0.10');
        addBooking($invoice, '0.20');
        addPayment($invoice, '0.30');

        expect(overdueIds())->toBe([]);
    });
});

describe('billable bookings', function () {
    test('bookings that are not billable do not add to the balance', function (array $attributes) {
        $invoice = billedInvoice('100.00');
        addBooking($invoice, '50.00', $attributes);

        expect(balanceOf($invoice))->toBe('100.00');
    })->with([
        'cancelled' => [['status' => BookingStatus::Cancelled, 'cancelled_at' => '2026-11-20 10:00:00']],
        'expired' => [['status' => BookingStatus::Expired, 'expires_at' => '2026-11-20 10:00:00']],
        'lapsed pending' => [['status' => BookingStatus::PendingPayment, 'expires_at' => '2026-12-01 08:59:00']],
        'soft deleted' => [['deleted_at' => '2026-11-20 10:00:00']],
    ]);

    test('billable bookings add to the balance', function (array $attributes) {
        $invoice = billedInvoice('100.00');
        addBooking($invoice, '50.00', $attributes);

        expect(balanceOf($invoice))->toBe('150.00');
    })->with([
        'completed' => [['status' => BookingStatus::Completed]],
        'pending still held' => [['status' => BookingStatus::PendingPayment, 'expires_at' => '2026-12-01 09:10:00']],
    ]);

    test('an invoice whose bookings are all cancelled has nothing to collect', function () {
        $invoice = Invoice::factory()->overdue()->create();
        addBooking($invoice, '100.00', ['status' => BookingStatus::Cancelled]);

        expect(overdueIds())->toBe([]);
    });

    test('an invoice without bookings has nothing to collect', function () {
        Invoice::factory()->overdue()->create(['amount' => 45]);

        expect(overdueIds())->toBe([]);
    });
});

describe('invoice state', function () {
    test('void and paid invoices are never overdue', function (string $state) {
        $invoice = Invoice::factory()->{$state}()->create(['due_at' => '2026-11-30']);
        addBooking($invoice, '100.00');

        expect(overdueIds())->toBe([]);
    })->with(['void', 'paid']);

    test('a soft deleted invoice is not overdue', function () {
        billedInvoice()->delete();

        expect(overdueIds())->toBe([]);
    });
});

describe('query', function () {
    test('it is resolved in a single query however many invoices there are', function () {
        collect(range(1, 3))->each(fn () => billedInvoice());
        DB::flushQueryLog();
        DB::enableQueryLog();

        $invoices = Invoice::query()->overdueInvoices()->get();

        expect($invoices)->toHaveCount(3)
            ->and($invoices->first()->balance)->toBe('100.00')
            ->and(DB::getQueryLog())->toHaveCount(1);
    });

    test('it can be counted and combined with other conditions', function () {
        $invoice = billedInvoice();
        billedInvoice();

        expect(Invoice::query()->overdueInvoices()->count())->toBe(2)
            ->and(Invoice::query()->overdueInvoices()->where('user_id', $invoice->user_id)->pluck('id')->all())
            ->toBe([$invoice->id]);
    });

    test('the seeder leaves exactly the three overdue invoices of the demo', function () {
        Carbon::setTestNow();
        $this->seed();

        expect(Invoice::query()->overdueInvoices()->orderBy('id')->get()->pluck('balance')->all())
            ->toBe(['9.00', '4.50', '9.00']);
    });
});
