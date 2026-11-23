<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Una factura vencida con saldo pendiente se acaba de marcar como avisada. Lo lanza
 * ReminderService::remindOverdue solo por las facturas que su UPDATE condicional cambió.
 *
 * $asOf es la fecha de referencia del vencimiento (AAAA-MM-DD), para que el job vuelva a
 * comprobar la factura con el mismo corte que usó el comando.
 */
class InvoiceOverdue implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Invoice $invoice, public string $asOf) {}
}
