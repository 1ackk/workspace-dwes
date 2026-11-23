<?php

namespace App\Listeners;

use App\Events\BookingExpired;
use App\Jobs\SendBookingExpiredMailJob;

/**
 * Se ejecuta en el momento y solo delega. El job no va a la cola (ver docs/eventos.md): se ejecuta
 * aquí mismo con dispatchSync, dentro del comando que libera las reservas.
 */
class SendBookingExpiredMail
{
    public function handle(BookingExpired $event): void
    {
        SendBookingExpiredMailJob::dispatchSync($event->booking);
    }
}
