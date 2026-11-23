<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Jobs\SendBookingCancelledMailJob;

/**
 * Se ejecuta en el momento y solo delega: el correo lo envía el job, en la cola "mail".
 */
class SendBookingCancelledMail
{
    public function handle(BookingCancelled $event): void
    {
        SendBookingCancelledMailJob::dispatch($event->booking);
    }
}
