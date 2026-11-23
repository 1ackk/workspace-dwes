<?php

namespace App\Exceptions;

class BookingNotConfirmableException extends BookingException
{
    public static function alreadyConfirmed(): self
    {
        return new self(__('La reserva ya está confirmada.'));
    }

    public static function cancelled(): self
    {
        return new self(__('La reserva está cancelada y no se puede confirmar.'));
    }

    public static function expired(): self
    {
        return new self(__('La reserva ha caducado y no se puede confirmar.'));
    }

    public static function completed(): self
    {
        return new self(__('La reserva ya está completada.'));
    }

    public static function holdExpired(): self
    {
        return new self(__('El tiempo para pagar la reserva ha terminado. Haz una reserva nueva.'));
    }

    public static function notFound(): self
    {
        return new self(__('La reserva no existe.'));
    }
}
