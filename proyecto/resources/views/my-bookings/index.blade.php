<x-layouts::app :title="__('Mis reservas')">
    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Mis reservas') }}</h1>

        <form method="GET" action="{{ route('my-bookings.index') }}" class="flex flex-wrap items-end gap-4">
            <x-form.date name="from" :label="__('Desde')" :value="request('from')" />
            <x-form.date name="to" :label="__('Hasta')" :value="request('to')" />

            <button type="submit" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Filtrar') }}</button>
            <a href="{{ route('my-bookings.index') }}" class="py-2 text-sm underline">{{ __('Quitar filtro') }}</a>
        </form>

        @if ($bookings->isEmpty())
            <p class="text-gray-700">{{ __('No hay reservas que mostrar.') }}</p>
        @else
            <table class="w-full bg-white text-left text-sm">
                <thead class="border-b border-gray-200">
                    <tr>
                        <th class="p-3 font-medium">{{ __('Fecha') }}</th>
                        <th class="p-3 font-medium">{{ __('Recursos') }}</th>
                        <th class="p-3 font-medium">{{ __('Estado') }}</th>
                        <th class="p-3 font-medium">{{ __('Total') }}</th>
                        <th class="p-3"><span class="sr-only">{{ __('Acciones') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bookings as $booking)
                        <tr class="border-b border-gray-100">
                            <td class="p-3">{{ $booking->date->format('d/m/Y') }}</td>
                            <td class="p-3">{{ $booking->resources->pluck('name')->join(', ') }}</td>
                            <td class="p-3">{{ $booking->status->label() }}</td>
                            <td class="p-3">{{ number_format((float) $booking->total, 2, ',', '.') }} €</td>
                            <td class="p-3">
                                <a href="{{ route('my-bookings.show', $booking) }}" class="underline">{{ __('Ver detalle') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{ $bookings->links() }}
        @endif
    </div>
</x-layouts::app>
