<?php

namespace App\Services\Occupancy;

use App\Models\BookingResource;
use App\Models\Resource;
use App\Models\Space;
use Carbon\CarbonInterface;

/**
 * Horas reservadas y disponibles de un recurso en un periodo.
 *
 * El cálculo se hace en PHP porque restar fechas no se escribe igual en SQLite y en MySQL.
 */
readonly class ResourceOccupancy
{
    public function __construct(
        public float $bookedHours,
        public float $availableHours,
    ) {}

    /**
     * El recurso debe traer cargadas las relaciones del scope occupancyByPeriod: su espacio y
     * solo las franjas bloqueantes que solapan con el periodo [$from, $to).
     */
    public static function of(Resource $resource, CarbonInterface $from, CarbonInterface $to): self
    {
        return new self(
            self::sumBookedHours($resource, $from, $to),
            self::sumAvailableHours($resource->space, $from, $to),
        );
    }

    public function percentage(): float
    {
        if ($this->availableHours <= 0) {
            return 0.0;
        }

        return round($this->bookedHours / $this->availableHours * 100, 2);
    }

    /**
     * Cada franja cuenta solo por la parte que queda dentro del periodo.
     */
    private static function sumBookedHours(Resource $resource, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) $resource->slots->sum(
            fn (BookingResource $slot) => self::hoursBetween($slot->start_at->max($from), $slot->end_at->min($to))
        );
    }

    /**
     * Recorre los días naturales que toca el periodo y suma, en cada uno, la intersección del
     * horario del espacio con [$from, $to). Un día sin intersección no suma nada.
     */
    private static function sumAvailableHours(Space $space, CarbonInterface $from, CarbonInterface $to): float
    {
        $hours = 0.0;

        for ($day = $from->copy()->startOfDay(); $day->lessThan($to); $day = $day->copy()->addDay()) {
            $start = $day->copy()->setTimeFromTimeString($space->opens_at)->max($from);
            $end = $day->copy()->setTimeFromTimeString($space->closes_at)->min($to);

            if ($end->greaterThan($start)) {
                $hours += self::hoursBetween($start, $end);
            }
        }

        return $hours;
    }

    private static function hoursBetween(CarbonInterface $start, CarbonInterface $end): float
    {
        return $start->diffInSeconds($end) / 3600;
    }
}
