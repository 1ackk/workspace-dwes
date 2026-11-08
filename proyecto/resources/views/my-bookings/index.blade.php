<x-layouts::app :title="__('Mis reservas')">
    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Mis reservas') }}</h1>

        @if ($bookings->isEmpty())
            <p class="text-gray-700">{{ __('Todavía no tienes reservas.') }}</p>
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
