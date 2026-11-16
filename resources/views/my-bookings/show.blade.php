<x-layouts::app :title="__('Detalle de la reserva')">
    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Detalle de la reserva') }}</h1>

        <dl class="grid grid-cols-2 gap-2 text-sm sm:max-w-md">
            <dt class="font-medium">{{ __('Fecha') }}</dt>
            <dd>{{ $booking->date->format('d/m/Y') }}</dd>

            <dt class="font-medium">{{ __('Estado') }}</dt>
            <dd>{{ $booking->status->label() }}</dd>

            <dt class="font-medium">{{ __('Total') }}</dt>
            <dd>{{ number_format((float) $booking->total, 2, ',', '.') }} €</dd>
        </dl>

        <table class="w-full bg-white text-left text-sm">
            <thead class="border-b border-gray-200">
                <tr>
                    <th class="p-3 font-medium">{{ __('Recurso') }}</th>
                    <th class="p-3 font-medium">{{ __('Espacio') }}</th>
                    <th class="p-3 font-medium">{{ __('Inicio') }}</th>
                    <th class="p-3 font-medium">{{ __('Fin') }}</th>
                    <th class="p-3 font-medium">{{ __('Precio') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($booking->resources as $resource)
                    <tr class="border-b border-gray-100">
                        <td class="p-3">{{ $resource->name }}</td>
                        <td class="p-3">{{ $resource->space->name }}</td>
                        <td class="p-3">{{ $resource->pivot->start_at->format('d/m/Y H:i') }}</td>
                        <td class="p-3">{{ $resource->pivot->end_at->format('d/m/Y H:i') }}</td>
                        <td class="p-3">{{ number_format((float) $resource->pivot->precio_final, 2, ',', '.') }} €</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <a href="{{ route('my-bookings.index') }}" class="text-sm underline">{{ __('Volver a mis reservas') }}</a>
    </div>
</x-layouts::app>
