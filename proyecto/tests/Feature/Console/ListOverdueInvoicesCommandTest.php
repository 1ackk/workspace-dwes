<?php

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;

const OVERDUE_HEADERS = ['Número', 'Email', 'Vencimiento', 'Importe', 'Saldo', 'Días de retraso'];

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');
});

function chargedInvoice(string $number, string $email, string $dueAt, string $total = '100.00'): Invoice
{
    $user = User::factory()->create(['email' => $email]);
    $invoice = Invoice::factory()->for($user, 'user')->create(['number' => $number, 'due_at' => $dueAt, 'amount' => $total]);
    Booking::factory()->confirmed()->for($user, 'user')->create(['invoice_id' => $invoice->id, 'total' => $total]);

    return $invoice;
}

test('shows the overdue invoices oldest first with their days late', function () {
    chargedInvoice('FAC-2', 'b@workspace.test', '2026-11-30', '50.00');
    chargedInvoice('FAC-1', 'a@workspace.test', '2026-11-21');
    chargedInvoice('FAC-3', 'c@workspace.test', '2026-12-05');

    $this->artisan('invoices:list-overdue')
        ->expectsTable(OVERDUE_HEADERS, [
            ['FAC-1', 'a@workspace.test', '21/11/2026', '100.00', '100.00', 10],
            ['FAC-2', 'b@workspace.test', '30/11/2026', '50.00', '50.00', 1],
        ])
        ->assertExitCode(0);
});

test('--as-of replaces today', function () {
    chargedInvoice('FAC-3', 'c@workspace.test', '2026-12-05');

    $this->artisan('invoices:list-overdue', ['--as-of' => '2026-12-08'])
        ->expectsTable(OVERDUE_HEADERS, [['FAC-3', 'c@workspace.test', '05/12/2026', '100.00', '100.00', 3]])
        ->assertExitCode(0);
});

test('the days late are whole across a daylight saving change', function () {
    chargedInvoice('FAC-4', 'd@workspace.test', '2026-10-20');

    $this->artisan('invoices:list-overdue', ['--as-of' => '2026-10-30'])
        ->expectsTable(OVERDUE_HEADERS, [['FAC-4', 'd@workspace.test', '20/10/2026', '100.00', '100.00', 10]])
        ->assertExitCode(0);
});

test('still lists an invoice whose user was soft deleted', function () {
    chargedInvoice('FAC-5', 'e@workspace.test', '2026-11-30')->user->delete();

    $this->artisan('invoices:list-overdue')
        ->expectsTable(OVERDUE_HEADERS, [['FAC-5', 'e@workspace.test', '30/11/2026', '100.00', '100.00', 1]])
        ->assertExitCode(0);
});

test('without results it says so and ends with 0', function () {
    $this->artisan('invoices:list-overdue')
        ->expectsOutput('No hay facturas vencidas a fecha 01/12/2026.')
        ->assertExitCode(0);
});

test('a malformed date ends with an error and code 1', function (string $date) {
    $this->artisan('invoices:list-overdue', ['--as-of' => $date])
        ->expectsOutput("Fecha no válida: \"{$date}\". Usa el formato AAAA-MM-DD.")
        ->assertExitCode(1);
})->with(['hoy', '2026-02-31', '2026-2-3', '01/12/2026', '']);
