<x-layouts::app :title="__('Factura :number', ['number' => $invoice->number])">
    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Factura :number', ['number' => $invoice->number]) }}</h1>

        <dl class="grid grid-cols-2 gap-2 text-sm sm:max-w-md">
            <dt class="font-medium">{{ __('Cliente') }}</dt>
            <dd>{{ $invoice->user->name }}</dd>

            <dt class="font-medium">{{ __('Periodo') }}</dt>
            <dd>{{ $invoice->period_start->format('d/m/Y') }} – {{ $invoice->period_end->format('d/m/Y') }}</dd>

            <dt class="font-medium">{{ __('Vencimiento') }}</dt>
            <dd>{{ $invoice->due_at?->format('d/m/Y') ?? '—' }}</dd>

            <dt class="font-medium">{{ __('Estado') }}</dt>
            <dd>
                {{ $invoice->status->label() }}
                @if ($isOverdue)
                    <span class="font-medium text-red-700">· {{ __('Vencida') }}</span>
                @endif
            </dd>

            <dt class="font-medium">{{ __('Importe') }}</dt>
            <dd>{{ number_format((float) $invoice->amount, 2, ',', '.') }} €</dd>

            <dt class="font-medium">{{ __('Saldo pendiente') }}</dt>
            <dd class="font-semibold">{{ number_format((float) $balance, 2, ',', '.') }} €</dd>
        </dl>

        <p class="text-sm text-gray-600">{{ __('El saldo pendiente es el total de las reservas no canceladas ni caducadas menos los pagos completados.') }}</p>

        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">{{ __('Reservas') }}</h2>

            @if ($invoice->bookings->isEmpty())
                <p class="text-sm text-gray-700">{{ __('Esta factura no tiene reservas.') }}</p>
            @else
                <table class="w-full bg-white text-left text-sm">
                    <thead class="border-b border-gray-200">
                        <tr>
                            <th class="p-3 font-medium">{{ __('Fecha') }}</th>
                            <th class="p-3 font-medium">{{ __('Estado') }}</th>
                            <th class="p-3 font-medium">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->bookings as $booking)
                            <tr class="border-b border-gray-100">
                                <td class="p-3">{{ $booking->date->format('d/m/Y') }}</td>
                                <td class="p-3">{{ $booking->status->label() }}</td>
                                <td class="p-3">{{ number_format((float) $booking->total, 2, ',', '.') }} €</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">{{ __('Pagos') }}</h2>

            @if ($invoice->payments->isEmpty())
                <p class="text-sm text-gray-700">{{ __('Todavía no hay pagos.') }}</p>
            @else
                <table class="w-full bg-white text-left text-sm">
                    <thead class="border-b border-gray-200">
                        <tr>
                            <th class="p-3 font-medium">{{ __('Fecha') }}</th>
                            <th class="p-3 font-medium">{{ __('Estado') }}</th>
                            <th class="p-3 font-medium">{{ __('Importe') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->payments as $payment)
                            <tr class="border-b border-gray-100">
                                <td class="p-3">{{ $payment->paid_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="p-3">{{ $payment->status->label() }}</td>
                                <td class="p-3">{{ number_format((float) $payment->amount, 2, ',', '.') }} €</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
</x-layouts::app>
