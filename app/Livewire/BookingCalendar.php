<?php

namespace App\Livewire;

use App\Enums\BookingStatus;
use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Resource;
use App\Services\Booking\AvailabilityGrid;
use App\Services\Booking\BookingService;
use App\Services\Booking\GridCell;
use App\Services\Pricing\InvalidSlotException;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\Slot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pantalla de reserva: elegir recurso, día y tramo; reservar (pendiente con retención) y pagar de
 * forma simulada (confirmar).
 *
 * Es un componente fino: la rejilla la calcula AvailabilityGrid, el precio PriceCalculator y la
 * reserva y la confirmación BookingService, que vuelve a validarlo todo. Las propiedades públicas
 * las puede cambiar el cliente, así que nada de lo que llega se da por bueno: se valida, el id de la
 * reserva está bloqueado (#[Locked]) y en cada acción se recarga la reserva y se autoriza de nuevo.
 */
#[Title('Reservar')]
class BookingCalendar extends Component
{
    public ?int $resourceId = null;

    public string $day = '';

    /** Hora "H:i" de la primera celda elegida. */
    public ?string $selectionStart = null;

    /** Hora "H:i" de la última celda elegida (incluida). */
    public ?string $selectionEnd = null;

    /** Reserva pendiente creada en esta pantalla. El cliente no la puede cambiar. */
    #[Locked]
    public ?int $pendingBookingId = null;

    /** Reserva recién confirmada, para enlazar a su detalle. */
    #[Locked]
    public ?int $confirmedBookingId = null;

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(): void
    {
        $this->day = today()->addDay()->toDateString();
        $this->resourceId = $this->resourceOptions() === [] ? null : (int) array_key_first($this->resourceOptions());
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'resourceId' => ['required', 'integer', Rule::exists('resources', 'id')->where('active', true)],
            'day' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'selectionStart' => ['nullable', 'date_format:H:i'],
            'selectionEnd' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'resourceId.required' => __('Elige un recurso.'),
            'resourceId.integer' => __('El recurso no es válido.'),
            'resourceId.exists' => __('El recurso no existe o no está disponible.'),
            'day.required' => __('Elige un día.'),
            'day.date_format' => __('El día no es válido.'),
            'day.after_or_equal' => __('No se puede reservar un día pasado.'),
            'selectionStart.date_format' => __('La hora elegida no es válida.'),
            'selectionEnd.date_format' => __('La hora elegida no es válida.'),
        ];
    }

    public function updatedResourceId(): void
    {
        $this->startOver();
        $this->validateOnly('resourceId');
    }

    public function updatedDay(): void
    {
        $this->startOver();
        $this->validateOnly('day');
    }

    /**
     * Clic en una celda. Primer clic: inicio. Segundo clic: final (si es anterior al inicio, se
     * intercambian). Un tramo que cruza una celda ocupada o pasada se rechaza entero.
     */
    public function select(string $time): void
    {
        $this->problem = null;
        $this->notice = null;
        $this->validateOnly('resourceId');
        $this->validateOnly('day');

        $cells = $this->cellsByKey();

        if (! isset($cells[$time]) || ! $cells[$time]->isFree()) {
            $this->problem = __('Esa franja no está libre.');
            $this->clearSelection();

            return;
        }

        if ($this->selectionStart === null || $this->selectionEnd !== null) {
            $this->selectionStart = $time;
            $this->selectionEnd = null;

            return;
        }

        [$first, $last] = $this->selectionStart <= $time ? [$this->selectionStart, $time] : [$time, $this->selectionStart];

        foreach ($cells as $key => $cell) {
            if ($key >= $first && $key <= $last && ! $cell->isFree()) {
                $this->problem = __('El tramo cruza una franja que no está libre. Elige un tramo seguido de franjas libres.');
                $this->clearSelection();

                return;
            }
        }

        $this->selectionStart = $first;
        $this->selectionEnd = $last;
    }

    public function clearSelection(): void
    {
        $this->selectionStart = null;
        $this->selectionEnd = null;
    }

    /**
     * Crea la reserva pendiente. Lo que se ha mostrado en pantalla no se da por bueno: el servicio
     * vuelve a comprobar la franja, el horario y la disponibilidad bajo bloqueo, y calcula el precio.
     */
    public function reserve(BookingService $bookings): void
    {
        Gate::authorize('create', Booking::class);
        $this->validate();
        $this->problem = null;
        $this->notice = null;

        $slot = $this->selectedSlot();

        if ($slot === null) {
            $this->problem = __('Elige primero un tramo: haz clic en la franja de inicio y en la de final.');

            return;
        }

        try {
            $booking = $bookings->createPending(auth()->user(), [$slot]);
        } catch (BookingException $exception) {
            $this->problem = $exception->getMessage();
            $this->clearSelection();
            unset($this->cells);

            return;
        }

        $this->pendingBookingId = $booking->id;
        $this->confirmedBookingId = null;
        $this->clearSelection();
        unset($this->cells);
    }

    /**
     * Pago simulado. Se recarga la reserva y se autoriza otra vez: la policy decide quién (solo el
     * dueño) y el servicio cuándo (retención vigente).
     */
    public function pay(BookingService $bookings): void
    {
        $this->problem = null;
        $booking = $this->pendingBooking();

        if ($booking === null) {
            $this->problem = __('No hay ninguna reserva pendiente de pago.');

            return;
        }

        Gate::authorize('confirm', $booking);

        try {
            $confirmed = $bookings->confirm($booking);
        } catch (BookingException $exception) {
            $this->problem = $exception->getMessage();
            $this->pendingBookingId = null;
            unset($this->cells);

            return;
        }

        $this->pendingBookingId = null;
        $this->confirmedBookingId = $confirmed->id;
        $this->notice = __('Pago recibido: tu reserva está confirmada. Te hemos enviado un correo con el resumen.');
        unset($this->cells);
    }

    /**
     * Oculta el panel de pago. La reserva no se cancela: se deja caducar sola al acabar su retención
     * (cancelarla exigiría la antelación del espacio y enviaría el correo de cancelación).
     */
    public function discard(): void
    {
        $expiresAt = $this->pendingBooking()?->expires_at;
        $this->pendingBookingId = null;
        $this->problem = null;
        $this->notice = __('Reserva descartada. Las franjas quedarán libres a las :time, cuando termine su retención.', [
            'time' => $expiresAt?->format('H:i') ?? '—',
        ]);
    }

    /**
     * Lo llama wire:poll mientras hay una reserva pendiente: detecta que la retención ha caducado.
     */
    public function checkHold(): void
    {
        $booking = $this->pendingBooking();

        if ($booking === null) {
            return;
        }

        $expired = $booking->status !== BookingStatus::PendingPayment
            || $booking->expires_at === null
            || $booking->expires_at->lessThanOrEqualTo(now());

        if ($expired) {
            $this->pendingBookingId = null;
            $this->problem = __('El tiempo para pagar la reserva ha terminado. Haz una reserva nueva.');
            unset($this->cells);
        }
    }

    /**
     * @return list<GridCell>
     */
    #[Computed]
    public function cells(): array
    {
        $resource = $this->resource();
        $day = $this->dayDate();

        if ($resource === null || $day === null || $this->getErrorBag()->has('day')) {
            return [];
        }

        return app(AvailabilityGrid::class)->cells($resource, $day);
    }

    /**
     * Precio orientativo del tramo elegido, con el mismo cálculo que usará el servicio.
     */
    #[Computed]
    public function price(): ?string
    {
        $slot = $this->selectedSlot();

        if ($slot === null) {
            return null;
        }

        try {
            return app(PriceCalculator::class)->priceSlot($slot->resource, $slot->start, $slot->end)->decimal();
        } catch (InvalidSlotException) {
            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.booking-calendar', [
            'resources' => $this->resourceOptions(),
            'pending' => $this->pendingBooking()?->load('resources.space'),
        ]);
    }

    /**
     * Recursos activos con su espacio: "Planta Baja · Sala 1".
     *
     * @return array<int, string>
     */
    private function resourceOptions(): array
    {
        return Resource::query()
            ->active()
            ->with('space')
            ->orderBy('space_id')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Resource $resource) => [$resource->id => $resource->space->name.' · '.$resource->name])
            ->all();
    }

    private function resource(): ?Resource
    {
        return $this->resourceId === null ? null : Resource::query()->active()->with('space')->find($this->resourceId);
    }

    private function dayDate(): ?CarbonInterface
    {
        // Como con las horas: el texto del cliente se comprueba antes de convertirlo (2026-02-31 no).
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $this->day, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d', $this->day, config('app.timezone')) ?: null;
    }

    /**
     * @return array<string, GridCell>
     */
    private function cellsByKey(): array
    {
        $cells = [];

        foreach ($this->cells() as $cell) {
            $cells[$cell->key()] = $cell;
        }

        return $cells;
    }

    /**
     * El tramo elegido como Slot: desde el inicio de la primera celda hasta el final de la última.
     */
    private function selectedSlot(): ?Slot
    {
        $resource = $this->resource();
        $day = $this->dayDate();

        $start = $this->timeOnDay($this->selectionStart);
        $lastCell = $this->timeOnDay($this->selectionEnd);

        if ($resource === null || $day === null || $start === null || $lastCell === null) {
            return null;
        }

        return new Slot($resource, $start, $lastCell->copy()->addMinutes(BookingService::BLOCK_MINUTES));
    }

    /**
     * Hora en la que termina el tramo elegido ("H:i"), para la pantalla.
     */
    public function selectionEndsAt(): ?string
    {
        return $this->timeOnDay($this->selectionEnd)?->addMinutes(BookingService::BLOCK_MINUTES)->format('H:i');
    }

    /**
     * Una hora "H:i" del día elegido, o null si no lo es. El cliente puede mandar cualquier texto:
     * se comprueba antes de convertirlo, para que una hora mal formada no rompa la pantalla.
     */
    private function timeOnDay(?string $time): ?Carbon
    {
        if ($time === null || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1 || $this->dayDate() === null) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d H:i', $this->day.' '.$time, config('app.timezone')) ?: null;
    }

    /**
     * La reserva pendiente, recargada de la base de datos en cada uso (nunca la del cliente).
     */
    private function pendingBooking(): ?Booking
    {
        return $this->pendingBookingId === null ? null : Booking::query()->find($this->pendingBookingId);
    }

    /**
     * Cambiar de recurso o de día empieza de cero.
     */
    private function startOver(): void
    {
        $this->clearSelection();
        $this->problem = null;
        $this->notice = null;
        unset($this->cells);
    }
}
