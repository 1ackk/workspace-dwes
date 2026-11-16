<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;

class BookingNotCancellableException extends BookingException
{
    public static function alreadyCancelled(): self
    {
        return new self(__('La reserva ya está cancelada.'));
    }

    public static function expired(): self
    {
        return new self(__('La reserva ha caducado.'));
    }

    public static function completed(): self
    {
        return new self(__('La reserva ya está completada.'));
    }

    public static function pastDeadline(int $hours, CarbonInterface $deadline): self
    {
        return new self(__('Solo se puede cancelar con :hours horas de antelación (hasta el :date a las :time).', [
            'hours' => $hours,
            'date' => $deadline->format('d/m/Y'),
            'time' => $deadline->format('H:i'),
        ]));
    }
}
