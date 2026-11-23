<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * La retención de una reserva pendiente venció sin pago y la reserva ha pasado a caducada. Lo lanza
 * BookingService::releaseUnpaid solo por las reservas que su UPDATE condicional cambió de verdad.
 */
class BookingExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Booking $booking) {}
}
