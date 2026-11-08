<?php

namespace App\Services\Pricing;

use App\Enums\TariffKind;
use App\Models\Resource;
use App\Models\Tariff;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Calcula el precio de las franjas a partir de las tarifas, sin guardar nada.
 *
 * Todo el cálculo usa enteros para no arrastrar errores de los float:
 * céntimos por hora × minutos × (10000 + recargo en puntos básicos), y solo se divide al
 * final de cada franja. Un recargo del 15,00 % son 1500 puntos básicos.
 */
class PriceCalculator
{
    private const int MINUTES_IN_DAY = 1440;

    /** 60 minutos por hora × 10000 puntos básicos: el 100 % sin recargo. */
    private const int DIVISOR = 600000;

    public function priceSlot(Resource $resource, CarbonInterface $start, CarbonInterface $end): SlotPrice
    {
        return $this->priceSlots([new Slot($resource, $start, $end)])->slots[0];
    }

    /**
     * Carga las tarifas una sola vez, sea cual sea el número de franjas.
     *
     * @param  list<Slot>  $slots
     */
    public function priceSlots(array $slots): BookingPrice
    {
        $tariffs = $this->loadTariffs($slots);

        return new BookingPrice(array_map(
            fn (Slot $slot) => $this->calculate($slot, $tariffs),
            $slots,
        ));
    }

    /**
     * Ordenadas por id para que, a igualdad de prioridad, gane la tarifa más reciente.
     *
     * @param  list<Slot>  $slots
     * @return Collection<int, Tariff>
     */
    private function loadTariffs(array $slots): Collection
    {
        $resourceIds = array_map(fn (Slot $slot) => $slot->resource->id, $slots);

        return Tariff::query()
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('resource_id')->orWhereIn('resource_id', $resourceIds))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Tariff>  $tariffs
     */
    private function calculate(Slot $slot, Collection $tariffs): SlotPrice
    {
        $start = $this->inAppTimezone($slot->start);
        $end = $this->inAppTimezone($slot->end);
        $this->assertValid($start, $end);

        // Primero las generales: al indexar por tipo, la última (la del recurso) sustituye a la otra.
        $applicable = $tariffs
            ->filter(fn (Tariff $tariff) => $this->appliesOnDay($tariff, $slot->resource, $start))
            ->sortBy(fn (Tariff $tariff) => $tariff->resource_id === null ? 0 : 1);

        $cuts = $this->cuts($applicable, $this->minuteOfDay($start), $this->minuteOfDay($end));

        $exactTotal = 0;
        $cents = 0;
        $segments = [];

        foreach (array_slice($cuts, 0, -1) as $index => $from) {
            $to = $cuts[$index + 1];

            $winners = $applicable
                ->filter(fn (Tariff $tariff) => $this->coversSegment($tariff, $from, $to))
                ->keyBy(fn (Tariff $tariff) => $tariff->kind->value);

            $base = $winners->pull(TariffKind::Base->value);
            $baseCents = $this->hundredths($base->price_per_hour ?? $slot->resource->hourly_rate);
            $surcharge = (int) $winners->sum(fn (Tariff $tariff) => $this->hundredths($tariff->surcharge_percent ?? '0.00'));

            $exactTotal += $baseCents * ($to - $from) * (10000 + $surcharge);

            // Redondeo acumulado: así los importes de los tramos siempre suman el total.
            $roundedTotal = $this->roundHalfUp($exactTotal);

            $segments[] = new PriceSegment(
                $start->copy()->setTime(intdiv($from, 60), $from % 60),
                $start->copy()->setTime(intdiv($to, 60), $to % 60),
                $baseCents,
                $surcharge,
                $roundedTotal - $cents,
            );

            $cents = $roundedTotal;
        }

        return new SlotPrice($slot, $cents, $segments);
    }

    private function inAppTimezone(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->setTimezone(config('app.timezone'));
    }

    private function assertValid(CarbonInterface $start, CarbonInterface $end): void
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw InvalidSlotException::endNotAfterStart();
        }

        if (! $start->isSameDay($end)) {
            throw InvalidSlotException::crossesMidnight();
        }

        if ($start->format('s.u') !== '00.000000' || $end->format('s.u') !== '00.000000') {
            throw InvalidSlotException::notWholeMinutes();
        }
    }

    /**
     * Si la tarifa cuenta ese día para ese recurso. El horario se mira después, tramo a tramo.
     */
    private function appliesOnDay(Tariff $tariff, Resource $resource, CarbonInterface $start): bool
    {
        $date = $start->toDateString();
        [$opens, $closes] = $this->window($tariff);

        return ($tariff->resource_id === null || $tariff->resource_id === $resource->id)
            && $this->isUsable($tariff)
            && ($tariff->kind !== TariffKind::Weekend || $start->isWeekend())
            && ($tariff->valid_from === null || $tariff->valid_from->toDateString() <= $date)
            && ($tariff->valid_until === null || $tariff->valid_until->toDateString() >= $date)
            && $opens < $closes;
    }

    /**
     * Una tarifa base solo cuenta si es de un recurso concreto y tiene precio:
     * las reglas generales son únicamente recargos.
     */
    private function isUsable(Tariff $tariff): bool
    {
        return $tariff->kind !== TariffKind::Base
            || ($tariff->resource_id !== null && $tariff->price_per_hour !== null);
    }

    /**
     * Los minutos donde cambia el precio: los extremos de la franja y los bordes de tarifa interiores.
     *
     * @param  Collection<int, Tariff>  $tariffs
     * @return list<int>
     */
    private function cuts(Collection $tariffs, int $from, int $to): array
    {
        $borders = $tariffs->flatMap(fn (Tariff $tariff) => $this->window($tariff))
            ->filter(fn (int $minute) => $minute > $from && $minute < $to)
            ->all();

        $cuts = array_unique([$from, $to, ...$borders]);
        sort($cuts);

        return $cuts;
    }

    private function coversSegment(Tariff $tariff, int $from, int $to): bool
    {
        [$opens, $closes] = $this->window($tariff);

        return $opens <= $from && $to <= $closes;
    }

    /**
     * El horario semiabierto [apertura, cierre) en minutos del día. Sin horario, todo el día.
     *
     * @return array{int, int}
     */
    private function window(Tariff $tariff): array
    {
        return [
            $tariff->starts_at === null ? 0 : $this->timeToMinutes($tariff->starts_at),
            $tariff->ends_at === null ? self::MINUTES_IN_DAY : $this->timeToMinutes($tariff->ends_at),
        ];
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }

    private function minuteOfDay(CarbonInterface $date): int
    {
        return $date->hour * 60 + $date->minute;
    }

    /**
     * Convierte un decimal con dos cifras ("12.50") a centésimas (1250) sin pasar por float.
     */
    private function hundredths(string $decimal): int
    {
        return (int) str_replace('.', '', $decimal);
    }

    private function roundHalfUp(int $exact): int
    {
        return intdiv($exact + intdiv(self::DIVISOR, 2), self::DIVISOR);
    }
}
