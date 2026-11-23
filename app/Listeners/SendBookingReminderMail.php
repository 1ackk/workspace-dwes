<?php

namespace App\Listeners;

use App\Events\BookingStartingSoon;
use App\Jobs\SendBookingReminderMailJob;

/**
 * Se ejecuta en el momento y solo delega. El job no va a la cola (ver docs/eventos.md): se ejecuta
 * aquí mismo con dispatchSync, dentro de bookings:remind-upcoming.
 */
class SendBookingReminderMail
{
    public function handle(BookingStartingSoon $event): void
    {
        SendBookingReminderMailJob::dispatchSync($event->booking);
    }
}
