<div class="flex flex-col gap-6">
    <h1 class="text-2xl font-semibold">{{ __('Reservar') }}</h1>

    <div class="grid gap-4 sm:max-w-xl sm:grid-cols-2">
        <x-form.select name="resourceId" :label="__('Recurso')" :options="$resources" :value="$resourceId" :placeholder="false" wire:model.live="resourceId" />
        <x-form.date name="day" :label="__('Día')" :value="$day" :min="today()->toDateString()" wire:model.live="day" />
    </div>

    @if ($notice)
        <p role="status" class="text-sm text-green-700">
            {{ $notice }}
            @if ($confirmedBookingId)
                <a href="{{ route('my-bookings.show', $confirmedBookingId) }}" class="underline">{{ __('Ver el detalle en Mis reservas') }}</a>
            @endif
        </p>
    @endif

    @if ($problem)
        <p role="alert" class="text-sm text-red-700">{{ $problem }}</p>
    @endif

    @if ($pending)
        {{-- Mientras hay una retención, cada 15 s se comprueba si ha caducado. --}}
        <section wire:poll.15s="checkHold" class="flex flex-col gap-3 rounded-md border border-amber-300 bg-amber-50 p-4 sm:max-w-xl">
            <h2 class="text-lg font-semibold">{{ __('Reserva retenida') }}</h2>

            <p class="text-sm">
                {{ __('Te guardamos la franja hasta las :time. Si no pagas antes, la reserva caduca y la franja queda libre.', ['time' => $pending->expires_at?->format('H:i')]) }}
            </p>

            <ul class="text-sm">
                @foreach ($pending->resources as $resource)
                    <li wire:key="pending-{{ $resource->pivot->id }}">
                        {{ $resource->space->name }} · {{ $resource->name }}:
                        {{ $resource->pivot->start_at->format('d/m/Y H:i') }} – {{ $resource->pivot->end_at->format('H:i') }}
                    </li>
                @endforeach
            </ul>

            <p class="text-sm font-semibold">{{ __('Total') }}: {{ number_format((float) $pending->total, 2, ',', '.') }} €</p>

            <div class="flex flex-wrap gap-3">
                <button type="button" wire:click="pay" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Pagar (simulado)') }}</button>
                <button type="button" wire:click="discard" class="cursor-pointer rounded-md border border-gray-300 px-4 py-2 text-sm font-medium hover:bg-gray-100">{{ __('Descartar') }}</button>
            </div>
        </section>
    @else
        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">{{ __('Franjas del día') }}</h2>

            <p class="text-sm text-gray-700">{{ __('Haz clic en la franja de inicio y después en la de final. Cada franja dura 30 minutos.') }}</p>

            @if ($this->cells === [])
                <p class="text-sm text-gray-700">{{ __('Elige un recurso y un día para ver sus franjas.') }}</p>
            @else
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                    @foreach ($this->cells as $cell)
                        @php
                            $key = $cell->key();
                            $isChosen = $selectionStart !== null && $key >= $selectionStart && $key <= ($selectionEnd ?? $selectionStart);
                            $text = $isChosen ? __('Elegida') : $cell->state->label();
                            $range = $cell->start->format('H:i').'–'.$cell->end->format('H:i');
                        @endphp
                        <button
                            type="button"
                            wire:key="cell-{{ $key }}"
                            wire:click="select('{{ $key }}')"
                            @disabled(! $cell->isFree())
                            aria-pressed="{{ $isChosen ? 'true' : 'false' }}"
                            aria-label="{{ $range }}, {{ $text }}"
                            @class([
                                'flex flex-col rounded-md border px-2 py-1 text-left text-sm focus:outline-2 focus:outline-offset-2 focus:outline-gray-900',
                                'cursor-pointer border-green-600 bg-green-50 hover:bg-green-100' => $cell->isFree() && ! $isChosen,
                                'cursor-pointer border-gray-900 bg-gray-900 text-white' => $isChosen,
                                'cursor-not-allowed border-red-300 bg-red-50 text-red-800' => $cell->state === \App\Enums\CellState::Busy,
                                'cursor-not-allowed border-gray-200 bg-gray-100 text-gray-500' => $cell->state === \App\Enums\CellState::Past,
                            ])
                        >
                            <span class="font-medium">{{ $range }}</span>
                            <span>{{ $text }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($selectionStart !== null)
                <div class="flex flex-col gap-2 sm:max-w-xl">
                    @if ($selectionEnd === null)
                        <p class="text-sm">{{ __('Inicio elegido a las :time. Ahora elige la franja final (puede ser la misma).', ['time' => $selectionStart]) }}</p>
                    @else
                        <p class="text-sm">
                            {{ __('Tramo elegido: de :from a :to.', ['from' => $selectionStart, 'to' => $this->selectionEndsAt() ?? '—']) }}
                            @if ($this->price !== null)
                                <strong>{{ __('Precio: :price €', ['price' => number_format((float) $this->price, 2, ',', '.')]) }}</strong>
                                <span class="text-gray-600">{{ __('(orientativo: el precio final lo calcula el sistema al reservar)') }}</span>
                            @endif
                        </p>

                        <div class="flex flex-wrap gap-3">
                            <button type="button" wire:click="reserve" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Reservar') }}</button>
                            <button type="button" wire:click="clearSelection" class="cursor-pointer rounded-md border border-gray-300 px-4 py-2 text-sm font-medium hover:bg-gray-100">{{ __('Borrar selección') }}</button>
                        </div>
                    @endif
                </div>
            @endif
        </section>
    @endif
</div>
