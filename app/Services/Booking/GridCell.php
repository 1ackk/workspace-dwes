<?php

namespace App\Services\Booking;

use App\Enums\CellState;
use Carbon\CarbonInterface;

/**
 * Una celda de la rejilla: un bloque [start, end) de BookingService::BLOCK_MINUTES minutos.
 */
readonly class GridCell
{
    public function __construct(
        public CarbonInterface $start,
        public CarbonInterface $end,
        public CellState $state,
    ) {}

    /** Hora de inicio "H:i": es lo que identifica la celda en la pantalla. */
    public function key(): string
    {
        return $this->start->format('H:i');
    }

    public function isFree(): bool
    {
        return $this->state === CellState::Free;
    }
}
