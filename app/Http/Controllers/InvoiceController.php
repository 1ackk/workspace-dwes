<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    /**
     * Detalle de una factura. InvoicePolicy::view deja verla al dueño, al contable y al
     * administrador; cualquier otro usuario recibe un 403 antes de leer nada más.
     */
    public function show(Invoice $invoice): View
    {
        Gate::authorize('view', $invoice);

        // Mismo cálculo de saldo que el scope overdueInvoices, en una consulta.
        $balance = Invoice::query()
            ->whereKey($invoice->id)
            ->selectSub(Invoice::balanceQuery(), 'balance')
            ->value('balance');

        $invoice->load(['user', 'bookings', 'payments']);

        return view('invoices.show', [
            'invoice' => $invoice,
            'balance' => number_format((float) $balance, 2, '.', ''),
            'isOverdue' => Invoice::query()->whereKey($invoice->id)->overdueInvoices()->exists(),
        ]);
    }
}
