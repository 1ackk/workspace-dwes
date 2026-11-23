<?php

namespace App\Services\Booking;

use App\Enums\CellState;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Resource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * La rejilla de disponibilidad de un recurso en un día: celdas de BookingService::BLOCK_MINUTES
 * minutos desde la apertura hasta el cierre de su espacio, en la zona horaria de la aplicación.
 *
 * Solo informa a la pantalla: quien decide si una franja se puede reservar es siempre
 * BookingService::createPending, que lo vuelve a comprobar todo bajo bloqueo.
 *
 * Número de consultas constante: como mucho una para el espacio y una para las franjas del recurso
 * que solapan el día, filtradas con Booking::blocking (la misma regla que el scope available). Da
 * igual cuántas reservas o celdas haya.
 */
class AvailabilityGrid
{
    /**
     * @return list<GridCell>
     */
    public function cells(Resource $resource, CarbonInterface $day): array
    {
        $timezone = config('app.timezone');
        $date = Carbon::parse($day->format('Y-m-d'), $timezone);
        $opens = $date->copy()->setTimeFromTimeString($resource->space->opens_at);
        $closes = $date->copy()->setTimeFromTimeString($resource->space->closes_at);
        $now = now();

        $busy = $this->blockingSlots($resource, $opens, $closes, $now);

        $cells = [];

        for ($start = $opens; $start->lessThan($closes); $start = $start->copy()->addMinutes(BookingService::BLOCK_MINUTES)) {
            $end = $start->copy()->addMinutes(BookingService::BLOCK_MINUTES);
            $cells[] = new GridCell($start, $end, $this->stateOf($start, $end, $busy, $now));
        }

        return $cells;
    }

    /**
     * Pasada si empieza ahora o antes (createPending rechaza esas franjas); si no, ocupada si solapa
     * con una franja bloqueante, con la regla semiabierta: inicio_A < fin_B y fin_A > inicio_B.
     *
     * @param  Collection<int, BookingResource>  $busy
     */
    private function stateOf(CarbonInterface $start, CarbonInterface $end, Collection $busy, CarbonInterface $now): CellState
    {
        if ($start->lessThanOrEqualTo($now)) {
            return CellState::Past;
        }

        $overlaps = $busy->contains(fn (BookingResource $slot) => $slot->start_at->lessThan($end) && $slot->end_at->greaterThan($start));

        return $overlaps ? CellState::Busy : CellState::Free;
    }

    /**
     * Una sola consulta: las franjas del recurso que solapan el horario del día y cuya reserva
     * ocupa (confirmada, completada o pendiente con la retención vigente).
     *
     * @return Collection<int, BookingResource>
     */
    private function blockingSlots(Resource $resource, CarbonInterface $from, CarbonInterface $to, CarbonInterface $now): Collection
    {
        return BookingResource::query()
            ->where('resource_id', $resource->id)
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->whereHas('booking', fn (Builder $bookings) => Booking::blocking($bookings, $now))
            ->get(['start_at', 'end_at'])
            ->toBase();
    }
}
