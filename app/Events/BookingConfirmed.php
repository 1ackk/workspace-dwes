<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Una reserva pendiente ha pasado a confirmada (pago correcto).
 *
 * ShouldDispatchAfterCommit: si se lanza dentro de una transacción, Laravel lo retiene hasta el
 * commit y lo descarta si hay rollback. Así ningún listener actúa sobre una confirmación que al
 * final no se guardó.
 */
class BookingConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Booking $booking) {}
}
