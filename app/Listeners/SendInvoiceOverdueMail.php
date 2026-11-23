<?php

namespace App\Listeners;

use App\Events\InvoiceOverdue;
use App\Jobs\SendInvoiceOverdueMailJob;

/**
 * Se ejecuta en el momento y solo delega: el correo lo envía el job, en la cola "mail".
 */
class SendInvoiceOverdueMail
{
    public function handle(InvoiceOverdue $event): void
    {
        SendInvoiceOverdueMailJob::dispatch($event->invoice, $event->asOf);
    }
}
