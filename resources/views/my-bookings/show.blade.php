<x-layouts::app :title="__('Detalle de la reserva')">
    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Detalle de la reserva') }}</h1>

        @session('status')
            <p class="text-sm text-green-700">{{ $value }}</p>
        @endsession

        @session('error')
            <p role="alert" class="text-sm text-red-700">{{ $value }}</p>
        @endsession

        <dl class="grid grid-cols-2 gap-2 text-sm sm:max-w-md">
            <dt class="font-medium">{{ __('Fecha') }}</dt>
            <dd>{{ $booking->date->format('d/m/Y') }}</dd>

            <dt class="font-medium">{{ __('Estado') }}</dt>
            <dd>{{ $booking->status->label() }}</dd>

            <dt class="font-medium">{{ __('Total') }}</dt>
            <dd>{{ number_format((float) $booking->total, 2, ',', '.') }} €</dd>

            @if ($booking->invoice)
                @can('view', $booking->invoice)
                    <dt class="font-medium">{{ __('Factura') }}</dt>
                    <dd><a href="{{ route('invoices.show', $booking->invoice) }}" class="underline">{{ $booking->invoice->number }}</a></dd>
                @endcan
            @endif
        </dl>

        @if ($booking->status === \App\Enums\BookingStatus::PendingPayment)
            @can('confirm', $booking)
                <form method="POST" action="{{ route('my-bookings.confirm', $booking) }}" class="flex flex-col gap-2 sm:max-w-md">
                    @csrf
                    <p class="text-sm text-gray-700">
                        {{ __('Reserva retenida hasta las :time. Pago simulado: no se cobra nada.', ['time' => $booking->expires_at?->format('H:i') ?? '—']) }}
                    </p>
                    <button type="submit" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Pagar (simulado)') }}</button>
                </form>
            @endcan
        @endif

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
