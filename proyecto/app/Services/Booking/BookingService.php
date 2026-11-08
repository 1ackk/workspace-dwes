<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\BookingNotCancellableException;
use App\Exceptions\InvalidBookingRequestException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Resource;
use App\Models\User;
use App\Services\Pricing\InvalidSlotException;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\Slot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BookingService
{
    private const int BLOCK_MINUTES = 30;

    private const int TRANSACTION_ATTEMPTS = 3;

    public function __construct(private PriceCalculator $calculator) {}

    /**
     * Crea una reserva pendiente de pago con todas las franjas, o no guarda nada.
     *
     * Antes de la transacción solo hay comprobaciones sin consultas. Es importante: en MySQL,
     * una lectura previa al bloqueo fijaría la foto de datos de la transacción y, tras esperar
     * el bloqueo, la comprobación de disponibilidad no vería la reserva del otro proceso.
     *
     * @param  list<Slot>  $slots
     */
    public function createPending(User $user, array $slots): Booking
    {
        $slots = array_map($this->inAppTimezone(...), $slots);
        $this->assertValidRequest($slots);

        return DB::transaction(fn () => $this->reserve($user, $slots), self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Cancela una reserva si el scope cancellable lo permite: es la única fuente de la regla.
     *
     * La fila se bloquea para serializar esta operación con otras sobre la misma reserva, y
     * el bloqueo es la primera consulta por el mismo motivo que en createPending.
     */
    public function cancel(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! Booking::query()->whereKey($locked->id)->cancellable()->exists()) {
                throw $this->whyNotCancellable($locked);
            }

            $locked->update([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

            return $locked;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Solo explica el motivo: la decisión ya la ha tomado el scope cancellable.
     */
    private function whyNotCancellable(Booking $booking): BookingNotCancellableException
    {
        return match ($booking->status) {
            BookingStatus::Cancelled => BookingNotCancellableException::alreadyCancelled(),
            BookingStatus::Expired => BookingNotCancellableException::expired(),
            BookingStatus::Completed => BookingNotCancellableException::completed(),
            default => $this->stillHoldsItsSlots($booking)
                ? $this->pastDeadline($booking)
                : BookingNotCancellableException::expired(),
        };
    }

    private function stillHoldsItsSlots(Booking $booking): bool
    {
        $query = Booking::query()->whereKey($booking->id);
        Booking::blocking($query);

        return $query->exists();
    }

    /**
     * El momento límite de la reserva es el de la franja que primero deja de poder cancelarse.
     */
    private function pastDeadline(Booking $booking): BookingNotCancellableException
    {
        $strictest = $booking->slots()->with('resource.space')->get()
            ->map(function (BookingResource $slot): array {
                $hours = $slot->resource->space->cancellation_notice_hours;

                return ['hours' => $hours, 'deadline' => $slot->start_at->copy()->subHours($hours)];
            })
            ->sortBy(fn (array $limit) => $limit['deadline']->getTimestamp())
            ->firstOrFail();

        return BookingNotCancellableException::pastDeadline($strictest['hours'], $strictest['deadline']);
    }

    private function inAppTimezone(Slot $slot): Slot
    {
        return new Slot(
            $slot->resource,
            $slot->start->copy()->setTimezone(config('app.timezone')),
            $slot->end->copy()->setTimezone(config('app.timezone')),
        );
    }

    /**
     * Comprobaciones que no necesitan la base de datos.
     *
     * @param  list<Slot>  $slots
     */
    private function assertValidRequest(array $slots): void
    {
        if ($slots === []) {
            throw InvalidBookingRequestException::noSlots();
        }

        $now = now();

        foreach ($slots as $slot) {
            $this->assertValidSlot($slot, $now);
        }

        $this->assertNoOverlapsBetween($slots);
    }

    private function assertValidSlot(Slot $slot, CarbonInterface $now): void
    {
        if ($slot->end->lessThanOrEqualTo($slot->start)) {
            throw InvalidSlotException::endNotAfterStart();
        }

        if (! $slot->start->isSameDay($slot->end)) {
            throw InvalidSlotException::crossesMidnight();
        }

        if (! $this->isOnGrid($slot->start) || ! $this->isOnGrid($slot->end)) {
            throw InvalidSlotException::offGrid();
        }

        if ($slot->start->lessThanOrEqualTo($now)) {
            throw InvalidSlotException::inThePast();
        }
    }

    private function isOnGrid(CarbonInterface $date): bool
    {
        return $date->minute % self::BLOCK_MINUTES === 0 && $date->format('s.u') === '00.000000';
    }

    /**
     * Estas franjas aún no están en la base de datos, así que se comparan en memoria:
     * ordenadas por inicio, basta con mirar cada una con la siguiente.
     *
     * @param  list<Slot>  $slots
     */
    private function assertNoOverlapsBetween(array $slots): void
    {
        foreach (collect($slots)->groupBy(fn (Slot $slot) => $slot->resource->id) as $resourceSlots) {
            $sorted = $resourceSlots->sortBy(fn (Slot $slot) => $slot->start->getTimestamp())->values();

            foreach ($sorted->sliding(2) as $pair) {
                if ($pair->first()->end->greaterThan($pair->last()->start)) {
                    throw InvalidBookingRequestException::overlappingSlots($pair->first()->resource);
                }
            }
        }
    }

    /**
     * @param  list<Slot>  $slots
     */
    private function reserve(User $user, array $slots): Booking
    {
        $resources = $this->lockResources($slots);
        $slots = array_map(fn (Slot $slot) => $this->withLockedResource($slot, $resources), $slots);

        foreach ($slots as $slot) {
            $this->assertAvailable($slot);
        }

        $price = $this->calculator->priceSlots($slots);

        $booking = Booking::create([
            'user_id' => $user->id,
            'date' => collect($slots)->min(fn (Slot $slot) => $slot->start->toDateString()),
            'status' => BookingStatus::PendingPayment,
            'expires_at' => now()->addMinutes(config()->integer('booking.hold_minutes')),
            'total' => $price->decimal(),
        ]);

        foreach ($price->slots as $slotPrice) {
            $booking->resources()->attach($slotPrice->slot->resource->id, [
                'start_at' => $slotPrice->slot->start,
                'end_at' => $slotPrice->slot->end,
                'precio_final' => $slotPrice->decimal(),
            ]);
        }

        return $booking->load('resources');
    }

    /**
     * Bloquea todos los recursos en una sola consulta y siempre en el mismo orden,
     * para que dos reservas simultáneas no se esperen mutuamente.
     *
     * @param  list<Slot>  $slots
     * @return Collection<int, \App\Models\Resource>
     */
    private function lockResources(array $slots): Collection
    {
        $ids = array_unique(array_map(fn (Slot $slot) => $slot->resource->id, $slots));

        return Resource::query()
            ->with('space')
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Comprueba el recurso con los datos recién bloqueados, y no con el modelo recibido.
     *
     * @param  Collection<int, \App\Models\Resource>  $resources
     */
    private function withLockedResource(Slot $slot, Collection $resources): Slot
    {
        $resource = $resources->get($slot->resource->id);

        if ($resource === null || ! $resource->active) {
            throw InvalidBookingRequestException::inactiveResource($slot->resource);
        }

        if (! $this->isWithinOpeningHours($slot, $resource)) {
            throw InvalidSlotException::outsideOpeningHours($resource);
        }

        return new Slot($resource, $slot->start, $slot->end);
    }

    private function isWithinOpeningHours(Slot $slot, Resource $resource): bool
    {
        return $slot->start->format('H:i') >= substr($resource->space->opens_at, 0, 5)
            && $slot->end->format('H:i') <= substr($resource->space->closes_at, 0, 5);
    }

    private function assertAvailable(Slot $slot): void
    {
        $isAvailable = Resource::query()
            ->whereKey($slot->resource->id)
            ->available($slot->start, $slot->end)
            ->exists();

        if (! $isAvailable) {
            throw new SlotUnavailableException($slot);
        }
    }
}
