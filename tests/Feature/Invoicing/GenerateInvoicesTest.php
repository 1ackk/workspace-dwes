<?php

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Events\InvoiceOverdue;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoicing\InvoiceGenerator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    // Hoy es 01/12/2026: noviembre ya ha terminado y diciembre está en curso.
    Carbon::setTestNow('2026-12-01 09:00:00');

    $this->generator = app(InvoiceGenerator::class);
    $this->november = Carbon::parse('2026-11-01');
});

/**
 * Reserva completada sin factura de $user con fecha $date y total $total.
 *
 * @param  array<string, mixed>  $attributes
 */
function completedOn(User $user, string $date, string $total = '10.00', array $attributes = []): Booking
{
    return Booking::factory()->for($user, 'user')->create([
        'status' => BookingStatus::Completed,
        'expires_at' => null,
        'date' => $date,
        'total' => $total,
        ...$attributes,
    ]);
}

/**
 * @return list<string>
 */
function invoiceNumbers(): array
{
    return Invoice::withTrashed()->orderBy('id')->pluck('number')->all();
}

describe('what is billed', function () {
    test('one user with several bookings gets one invoice with the exact sum', function () {
        $user = User::factory()->create();
        $bookings = collect([
            completedOn($user, '2026-11-03', '12.35'),
            completedOn($user, '2026-11-15', '7.40'),
            completedOn($user, '2026-11-28', '0.25'),
        ]);

        $this->artisan('invoices:generate', ['month' => '2026-11'])
            ->expectsOutput('Facturas generadas en 11/2026: 1. Reservas facturadas: 3. Importe total: 20.00 €.')
            ->assertExitCode(0);

        $invoice = Invoice::query()->sole();

        expect($invoice->user_id)->toBe($user->id)
            ->and($invoice->number)->toBe('FAC-2026-000001')
            ->and($invoice->amount)->toBe('20.00')
            ->and($invoice->status)->toBe(InvoiceStatus::Pending)
            ->and($invoice->period_start->toDateString())->toBe('2026-11-01')
            ->and($invoice->period_end->toDateString())->toBe('2026-11-30')
            ->and($invoice->issued_at?->toDateTimeString())->toBe('2026-12-01 09:00:00')
            ->and($invoice->due_at?->toDateString())->toBe('2026-12-15');

        $bookings->each(fn (Booking $booking) => expect($booking->fresh()->invoice_id)->toBe($invoice->id));
    });

    test('cents add up exactly, without floats', function () {
        $user = User::factory()->create();
        // 0.1 + 0.2 en float no da 0.3; en céntimos, sí.
        completedOn($user, '2026-11-10', '0.10');
        completedOn($user, '2026-11-11', '0.20');
        collect(range(1, 7))->each(fn (int $day) => completedOn($user, sprintf('2026-11-%02d', 12 + $day), '33.33'));

        $this->generator->generate($this->november);

        expect(Invoice::query()->sole()->amount)->toBe('233.61');
    });

    test('several users get one invoice each, in ascending user order', function () {
        $first = User::factory()->create();
        $second = User::factory()->create();
        completedOn($second, '2026-11-05', '20.00');
        completedOn($first, '2026-11-06', '30.00');
        completedOn($first, '2026-11-07', '5.00');

        $batch = $this->generator->generate($this->november);

        expect($batch->invoices())->toBe(2)
            ->and($batch->bookings)->toBe(3)
            ->and($batch->totalDecimal())->toBe('55.00')
            ->and(Invoice::query()->orderBy('number')->get(['user_id', 'number', 'amount'])->toArray())->toBe([
                ['user_id' => $first->id, 'number' => 'FAC-2026-000001', 'amount' => '35.00'],
                ['user_id' => $second->id, 'number' => 'FAC-2026-000002', 'amount' => '20.00'],
            ]);
    });

    test('only completed, unbilled, not deleted bookings of the month are billed', function () {
        $user = User::factory()->create();
        $firstDay = completedOn($user, '2026-11-01', '1.00');
        $lastDay = completedOn($user, '2026-11-30', '2.00');
        $excluded = collect([
            completedOn($user, '2026-10-31', '100.00'),
            completedOn($user, '2026-12-01', '100.00'),
            completedOn($user, '2026-11-10', '100.00', ['status' => BookingStatus::Confirmed]),
            completedOn($user, '2026-11-10', '100.00', ['status' => BookingStatus::Cancelled]),
            completedOn($user, '2026-11-10', '100.00', ['status' => BookingStatus::Expired]),
            completedOn($user, '2026-11-10', '100.00', ['status' => BookingStatus::PendingPayment, 'expires_at' => now()->addMinutes(5)]),
            completedOn($user, '2026-11-10', '100.00', ['invoice_id' => Invoice::factory()->create(['number' => 'FAC-2026-000050'])->id]),
            tap(completedOn($user, '2026-11-10', '100.00'))->delete(),
        ]);

        $this->generator->generate($this->november);

        $invoice = Invoice::query()->where('user_id', $user->id)->sole();

        expect($invoice->amount)->toBe('3.00')
            ->and($firstDay->fresh()->invoice_id)->toBe($invoice->id)
            ->and($lastDay->fresh()->invoice_id)->toBe($invoice->id);

        $excluded->each(fn (Booking $booking) => expect(Booking::withTrashed()->find($booking->id)->invoice_id)->not->toBe($invoice->id));
    });

    test('a soft deleted user is billed too: the history is kept', function () {
        $gone = User::factory()->create();
        completedOn($gone, '2026-11-10', '15.00');
        $gone->delete();

        $this->generator->generate($this->november);

        expect(Invoice::query()->sole()->user_id)->toBe($gone->id);
    });

    test('a second run of the same month creates nothing and says so', function () {
        completedOn(User::factory()->create(), '2026-11-10');

        $this->artisan('invoices:generate', ['month' => '2026-11'])->assertExitCode(0);
        $this->artisan('invoices:generate', ['month' => '2026-11'])
            ->expectsOutput('No hay reservas que facturar en 11/2026.')
            ->assertExitCode(0);

        expect(Invoice::count())->toBe(1);
    });

    test('a month without bookings says so with exit code 0', function () {
        $this->artisan('invoices:generate', ['month' => '2026-10'])
            ->expectsOutput('No hay reservas que facturar en 10/2026.')
            ->assertExitCode(0);
    });

    test('the due date follows the configuration', function () {
        config(['invoicing.due_days' => 30]);
        completedOn(User::factory()->create(), '2026-11-10');

        $this->generator->generate($this->november);

        expect(Invoice::query()->sole()->due_at?->toDateString())->toBe('2026-12-30');
    });

    test('generates no events and leaves existing invoices untouched', function () {
        $existing = Invoice::factory()->create(['number' => 'FAC-2026-000010', 'amount' => '99.00']);
        $before = $existing->fresh()->toArray();
        completedOn(User::factory()->create(), '2026-11-10');

        Event::fake();
        $this->generator->generate($this->november);

        Event::assertNotDispatched(InvoiceOverdue::class);
        expect($existing->fresh()->toArray())->toBe($before);
    });
});

describe('options', function () {
    test('--user bills only that user', function () {
        $chosen = User::factory()->create();
        $other = User::factory()->create();
        completedOn($chosen, '2026-11-10');
        completedOn($other, '2026-11-10');

        $this->artisan('invoices:generate', ['month' => '2026-11', '--user' => (string) $chosen->id])
            ->expectsOutput('Facturas generadas en 11/2026: 1. Reservas facturadas: 1. Importe total: 10.00 €.')
            ->assertExitCode(0);

        expect(Invoice::query()->sole()->user_id)->toBe($chosen->id);
    });

    test('--user accepts a soft deleted user', function () {
        $gone = User::factory()->create();
        completedOn($gone, '2026-11-10');
        $gone->delete();

        $this->artisan('invoices:generate', ['month' => '2026-11', '--user' => (string) $gone->id])->assertExitCode(0);

        expect(Invoice::count())->toBe(1);
    });

    test('--dry-run shows what it would create and writes nothing', function () {
        $user = User::factory()->create(['email' => 'ana@workspace.test']);
        completedOn($user, '2026-11-10', '12.50');
        completedOn($user, '2026-11-11', '0.50');

        $this->artisan('invoices:generate', ['month' => '2026-11', '--dry-run' => true])
            ->expectsTable(['Usuario', 'Reservas', 'Importe'], [['ana@workspace.test', 2, '13.00']])
            ->expectsOutput('Simulación: se crearían 1 facturas. No se ha escrito nada.')
            ->assertExitCode(0);

        expect(Invoice::count())->toBe(0)
            ->and(Booking::query()->whereNotNull('invoice_id')->count())->toBe(0);
    });

    test('--dry-run without bookings says so', function () {
        $this->artisan('invoices:generate', ['month' => '2026-11', '--dry-run' => true])
            ->expectsOutput('No hay reservas que facturar en 11/2026.')
            ->assertExitCode(0);
    });

    test('rejects a month that is not finished or not valid with exit code 1', function (string $month, string $reason) {
        completedOn(User::factory()->create(), '2026-11-10');

        $this->artisan('invoices:generate', ['month' => $month])
            ->expectsOutput("Mes no válido: \"{$month}\". {$reason}")
            ->assertExitCode(1);

        expect(Invoice::count())->toBe(0);
    })->with([
        'mes en curso' => ['2026-12', 'Solo se puede facturar un mes ya terminado.'],
        'mes futuro' => ['2027-01', 'Solo se puede facturar un mes ya terminado.'],
        'mes 13' => ['2026-13', 'El mes debe tener el formato AAAA-MM, por ejemplo 2026-10.'],
        'sin cero' => ['2026-1', 'El mes debe tener el formato AAAA-MM, por ejemplo 2026-10.'],
        'con barra' => ['2026/11', 'El mes debe tener el formato AAAA-MM, por ejemplo 2026-10.'],
        'con día' => ['2026-11-01', 'El mes debe tener el formato AAAA-MM, por ejemplo 2026-10.'],
        'texto' => ['noviembre', 'El mes debe tener el formato AAAA-MM, por ejemplo 2026-10.'],
    ]);

    test('the last day of the month must be before today, in the application timezone', function () {
        // 00:30 en Madrid del 01/12 es aún 30/11 en UTC: noviembre ya ha terminado en Madrid.
        Carbon::setTestNow(Carbon::parse('2026-12-01 00:30:00', 'Europe/Madrid'));

        $this->artisan('invoices:generate', ['month' => '2026-11', '--dry-run' => true])->assertExitCode(0);

        Carbon::setTestNow(Carbon::parse('2026-11-30 23:30:00', 'Europe/Madrid'));

        $this->artisan('invoices:generate', ['month' => '2026-11', '--dry-run' => true])->assertExitCode(1);
    });

    test('rejects a --user that does not exist with exit code 1', function (string $user) {
        $this->artisan('invoices:generate', ['month' => '2026-11', '--user' => $user])
            ->expectsOutput("No existe ningún usuario con id \"{$user}\".")
            ->assertExitCode(1);
    })->with(['999999', 'abc', '0', '-1']);
});

describe('invoice number', function () {
    test('continues the series of the issue year, also counting deleted invoices', function () {
        Invoice::factory()->create(['number' => 'FAC-2026-000006']);
        Invoice::factory()->create(['number' => 'FAC-2026-000009'])->delete();
        Invoice::factory()->create(['number' => 'FAC-2025-000400']);
        Invoice::factory()->create(['number' => 'FAC-2026-RECT01']);
        completedOn(User::factory()->create(), '2026-11-10');

        $this->generator->generate($this->november);

        expect(Invoice::query()->latest('id')->first()->number)->toBe('FAC-2026-000010');
    });

    test('uses the year when it is issued, not the year of the period', function () {
        Carbon::setTestNow('2027-01-10 09:00:00');
        completedOn(User::factory()->create(), '2026-12-10');

        $this->generator->generate(Carbon::parse('2026-12-01'));

        expect(Invoice::query()->sole()->number)->toBe('FAC-2027-000001');
    });

    test('if another run takes the number first, the user is retried and billed once', function () {
        $user = User::factory()->create();
        completedOn($user, '2026-11-10');
        $taken = false;

        // Justo después de calcular el número, otra ejecución crea una factura con ese mismo número:
        // el INSERT choca con el índice único y la transacción del usuario se repite.
        DB::listen(function (QueryExecuted $query) use (&$taken): void {
            if (! $taken && str_contains($query->sql, 'like')) {
                $taken = true;
                DB::table('invoices')->insert([
                    'user_id' => User::factory()->create()->id,
                    'number' => 'FAC-2026-000001',
                    'period_start' => '2026-11-01',
                    'period_end' => '2026-11-30',
                    'amount' => '0.00',
                    'status' => 'pending',
                ]);
            }
        });

        $batch = $this->generator->generate($this->november);

        expect($taken)->toBeTrue()
            ->and($batch->invoices())->toBe(1)
            ->and($batch->warnings)->toBe([])
            ->and(Invoice::query()->where('user_id', $user->id)->sole()->amount)->toBe('10.00');
    });

    test('after three collisions the user is skipped with a warning and the batch goes on', function () {
        $unlucky = User::factory()->create();
        $lucky = User::factory()->create();
        completedOn($unlucky, '2026-11-10');
        completedOn($lucky, '2026-11-11');
        $collisions = 0;
        $inserting = false;

        DB::listen(function (QueryExecuted $query) use (&$collisions, &$inserting): void {
            if (! $inserting && $collisions < 3 && str_contains($query->sql, 'like')) {
                $inserting = true;
                $collisions++;
                DB::table('invoices')->insert([
                    'user_id' => User::factory()->create()->id,
                    'number' => 'FAC-2026-'.sprintf('%06d', Invoice::withTrashed()->count() + 1),
                    'period_start' => '2026-11-01',
                    'period_end' => '2026-11-30',
                    'amount' => '0.00',
                    'status' => 'pending',
                ]);
                $inserting = false;
            }
        });

        $this->artisan('invoices:generate', ['month' => '2026-11'])
            ->expectsOutput("No se pudo obtener un número de factura libre para el usuario {$unlucky->id} tras 3 intentos. Se ha omitido.")
            ->expectsOutput('Facturas generadas en 11/2026: 1. Reservas facturadas: 1. Importe total: 10.00 €.')
            ->assertExitCode(0);

        expect(Invoice::query()->where('user_id', $unlucky->id)->exists())->toBeFalse()
            ->and(Booking::query()->where('user_id', $unlucky->id)->sole()->invoice_id)->toBeNull()
            ->and(Invoice::query()->where('user_id', $lucky->id)->sole()->amount)->toBe('10.00');
    });
});

describe('atomicity', function () {
    test('if another run bills a booking between the lock and the update, nothing wrong is left', function () {
        $user = User::factory()->create();
        $first = completedOn($user, '2026-11-10', '10.00');
        $second = completedOn($user, '2026-11-11', '20.00');
        $foreign = Invoice::factory()->for($user, 'user')->create(['number' => 'OTRA-0001', 'amount' => '20.00']);
        $selects = 0;

        // 1.ª lectura: los usuarios a facturar. 2.ª: el bloqueo de sus reservas. Justo después, otra
        // ejecución factura la segunda reserva: el UPDATE ya solo cambia 1 de las 2.
        DB::listen(function (QueryExecuted $query) use (&$selects, $second, $foreign): void {
            if (str_starts_with($query->sql, 'select') && str_contains(withoutEngineSyntax($query->sql), 'from bookings') && ++$selects === 2) {
                DB::table('bookings')->where('id', $second->id)->update(['invoice_id' => $foreign->id]);
            }
        });

        $this->artisan('invoices:generate', ['month' => '2026-11'])
            ->expectsOutput("Las reservas del usuario {$user->id} cambiaron mientras se facturaban (1 de 2). No se ha creado su factura.")
            ->expectsOutput('No hay reservas que facturar en 11/2026.')
            ->assertExitCode(0);

        // La transacción entera se deshizo: ni factura nueva ni la primera reserva asignada a medias.
        // (En SQLite el cambio simulado va por la misma conexión y se deshace con ella, así que la
        // segunda también vuelve a quedar sin factura; con dos procesos reales en MySQL lo cubre
        // BookingConcurrencyTest.)
        expect(Invoice::query()->pluck('number')->all())->toBe(['OTRA-0001'])
            ->and($first->fresh()->invoice_id)->toBeNull()
            ->and($second->fresh()->invoice_id)->toBeNull();

        // La siguiente ejecución, ya sin interferencias, factura las dos con su importe correcto.
        $this->generator->generate($this->november);

        expect(Invoice::query()->where('number', '!=', 'OTRA-0001')->sole()->amount)->toBe('30.00');
    });

    test('if another run bills all the bookings before the lock, the user is skipped silently', function () {
        $user = User::factory()->create();
        $booking = completedOn($user, '2026-11-10');
        $foreign = Invoice::factory()->for($user, 'user')->create(['number' => 'OTRA-0002']);
        $done = false;

        // Tras leer a quién facturar, y antes de bloquear, otra ejecución factura su única reserva.
        DB::listen(function (QueryExecuted $query) use (&$done, $booking, $foreign): void {
            if (! $done && str_starts_with($query->sql, 'select')) {
                $done = true;
                DB::table('bookings')->where('id', $booking->id)->update(['invoice_id' => $foreign->id]);
            }
        });

        $batch = $this->generator->generate($this->november);

        expect($batch->invoices())->toBe(0)
            ->and($batch->warnings)->toBe([])
            ->and(Invoice::query()->pluck('number')->all())->toBe(['OTRA-0002']);
    });

    test('every invoice amount matches the bookings assigned to it', function () {
        collect(range(1, 3))->each(function (int $n): void {
            $user = User::factory()->create();
            collect(range(1, $n))->each(fn (int $day) => completedOn($user, sprintf('2026-11-%02d', $day), sprintf('%d.%02d', $n, $day)));
        });

        $this->generator->generate($this->november);

        Invoice::query()->get()->each(function (Invoice $invoice): void {
            $cents = $invoice->bookings->sum(fn (Booking $booking) => InvoiceGenerator::toCents($booking->total));

            expect(InvoiceGenerator::toCents($invoice->amount))->toBe($cents);
        });
    });
});

test('the generated decimal helpers round-trip cents', function (int $cents, string $decimal) {
    expect(InvoiceGenerator::toDecimal($cents))->toBe($decimal)
        ->and(InvoiceGenerator::toCents($decimal))->toBe($cents);
})->with([[0, '0.00'], [5, '0.05'], [1250, '12.50'], [123456789, '1234567.89']]);
