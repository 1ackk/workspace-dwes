<?php

use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);

    $this->owner = userWithRole(RoleName::Member);
    $this->invoice = invoiceFor($this->owner, '100.00', ['number' => 'FAC-2026-000123']);
});

/**
 * Factura de $user con una reserva confirmada que suma $total. Por defecto vence en 15 días.
 *
 * @param  array<string, mixed>  $attributes
 */
function invoiceFor(User $user, string $total, array $attributes = []): Invoice
{
    $invoice = Invoice::factory()->for($user, 'user')->create(['amount' => $total, ...$attributes]);

    Booking::factory()->confirmed()->create([
        'user_id' => $user->id,
        'invoice_id' => $invoice->id,
        'total' => $total,
    ]);

    return $invoice;
}

describe('allowed', function () {
    test('the owner sees the invoice with its pending balance', function () {
        $this->actingAs($this->owner)
            ->get(route('invoices.show', $this->invoice))
            ->assertOk()
            ->assertSee('FAC-2026-000123')
            ->assertSee('Pendiente')
            ->assertSee('100,00 €')
            ->assertDontSee('Vencida');
    });

    test('the balance subtracts only completed payments', function () {
        Payment::factory()->create(['invoice_id' => $this->invoice->id, 'amount' => '40.00']);
        Payment::factory()->failed()->create(['invoice_id' => $this->invoice->id, 'amount' => '60.00']);

        $this->actingAs($this->owner)
            ->get(route('invoices.show', $this->invoice))
            ->assertOk()
            ->assertViewHas('balance', '60.00')
            ->assertSee('60,00 €')
            ->assertSee('Fallido');
    });

    test('a cancelled booking does not count in the balance', function () {
        Booking::factory()->cancelled()->create([
            'user_id' => $this->owner->id,
            'invoice_id' => $this->invoice->id,
            'total' => '50.00',
        ]);

        $this->actingAs($this->owner)
            ->get(route('invoices.show', $this->invoice))
            ->assertViewHas('balance', '100.00');
    });

    test('an overdue invoice is marked as overdue', function () {
        $overdue = invoiceFor($this->owner, '30.00', ['due_at' => '2026-11-30']);

        $this->actingAs($this->owner)
            ->get(route('invoices.show', $overdue))
            ->assertOk()
            ->assertViewHas('isOverdue', true)
            ->assertSee('Vencida');
    });

    test('an invoice without bookings has no balance', function () {
        $empty = Invoice::factory()->for($this->owner, 'user')->create();

        $this->actingAs($this->owner)
            ->get(route('invoices.show', $empty))
            ->assertOk()
            ->assertViewHas('balance', '0.00')
            ->assertSee('Esta factura no tiene reservas.');
    });

    test('the accountant and the administrator see any invoice', function (RoleName $role) {
        $this->actingAs(userWithRole($role))
            ->get(route('invoices.show', $this->invoice))
            ->assertOk()
            ->assertSee('FAC-2026-000123');
    })->with([
        'contable' => RoleName::Accountant,
        'administrador' => RoleName::Admin,
    ]);

    test('the booking detail links to its invoice', function () {
        $booking = $this->invoice->bookings()->firstOrFail();

        $this->actingAs($this->owner)
            ->get(route('my-bookings.show', $booking))
            ->assertOk()
            ->assertSee(route('invoices.show', $this->invoice));
    });
});

describe('denied', function () {
    test('another member cannot read the invoice', function () {
        $this->actingAs(userWithRole(RoleName::Member))
            ->get(route('invoices.show', $this->invoice))
            ->assertForbidden()
            ->assertDontSee('FAC-2026-000123');
    });

    test('roles without access to invoices receive a 403', function (RoleName $role) {
        $this->actingAs(userWithRole($role))
            ->get(route('invoices.show', $this->invoice))
            ->assertForbidden();
    })->with([
        'recepción' => RoleName::Reception,
        'responsable' => RoleName::SpaceManager,
    ]);

    test('a guest goes to the login', function () {
        $this->get(route('invoices.show', $this->invoice))->assertRedirect(route('login'));
    });

    test('a missing invoice returns 404', function () {
        $this->actingAs($this->owner)->get(route('invoices.show', 999999))->assertNotFound();
    });
});

test('the balance uses the same calculation as overdueInvoices', function () {
    $overdue = invoiceFor($this->owner, '80.00', ['due_at' => '2026-11-30']);
    Payment::factory()->create(['invoice_id' => $overdue->id, 'amount' => '25.50', 'status' => PaymentStatus::Completed]);

    $fromScope = Invoice::query()->whereKey($overdue->id)->overdueInvoices()->firstOrFail()->balance;

    $this->actingAs($this->owner)
        ->get(route('invoices.show', $overdue))
        ->assertViewHas('balance', $fromScope);

    expect($fromScope)->toBe('54.50');
});
