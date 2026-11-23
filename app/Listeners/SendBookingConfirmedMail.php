<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Jobs\SendBookingConfirmedMailJob;

/**
 * Se ejecuta en el momento (no va a la cola) y solo delega: el envío del correo, que es lo
 * lento y lo que puede fallar, lo hace el job en la cola "mail".
 *
 * Laravel lo registra solo: descubre los listeners de app/Listeners por el tipo de handle().
 */
class SendBookingConfirmedMail
{
    public function handle(BookingConfirmed $event): void
    {
        SendBookingConfirmedMailJob::dispatch($event->booking);
    }
}
