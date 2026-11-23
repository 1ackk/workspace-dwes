<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Una reserva confirmada empieza pronto y se acaba de marcar como recordada. Lo lanza
 * ReminderService::remindUpcoming solo por las reservas que su UPDATE condicional cambió.
 */
class BookingStartingSoon implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Booking $booking) {}
}
