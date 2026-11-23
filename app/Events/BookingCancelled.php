<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Una reserva se ha cancelado de verdad. Lo lanza BookingService::cancel dentro de su transacción:
 * ShouldDispatchAfterCommit lo retiene hasta el commit y lo descarta si hay rollback.
 */
class BookingCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Booking $booking) {}
}
