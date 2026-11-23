<?php

namespace App\Enums;

/**
 * Estado de una celda de la rejilla de disponibilidad (App\Services\Booking\AvailabilityGrid).
 * No se guarda en la base de datos: se calcula al pintar la rejilla.
 */
enum CellState: string
{
    case Free = 'free';
    case Busy = 'busy';
    case Past = 'past';

    public function label(): string
    {
        return match ($this) {
            self::Free => __('Libre'),
            self::Busy => __('Ocupada'),
            self::Past => __('Pasada'),
        };
    }
}
