<x-layouts::app :title="__('Contabilidad')">
    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Contabilidad') }}</h1>

        <section class="flex flex-col gap-3 sm:max-w-md">
            <h2 class="text-lg font-semibold">{{ __('Generar facturas') }}</h2>

            <p class="text-sm text-gray-700">{{ __('Crea una factura por usuario con las reservas completadas y sin facturar de un mes ya terminado.') }}</p>

            @session('status')
                <p class="whitespace-pre-line text-sm text-green-700">{{ $value }}</p>
            @endsession

            @session('error')
                <p role="alert" class="whitespace-pre-line text-sm text-red-700">{{ $value }}</p>
            @endsession

            <form method="POST" action="{{ route('accounting.invoices.generate') }}" class="flex flex-col gap-3">
                @csrf

                <x-form.input name="month" type="month" :label="__('Mes')" :value="$lastMonth" required />

                <button type="submit" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Generar facturas del mes') }}</button>
            </form>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">{{ __('Últimas facturas') }}</h2>

            @if ($invoices->isEmpty())
                <p class="text-sm text-gray-700">{{ __('Todavía no hay facturas.') }}</p>
            @else
                <table class="w-full bg-white text-left text-sm">
                    <thead class="border-b border-gray-200">
                        <tr>
                            <th class="p-3 font-medium">{{ __('Número') }}</th>
                            <th class="p-3 font-medium">{{ __('Usuario') }}</th>
                            <th class="p-3 font-medium">{{ __('Periodo') }}</th>
                            <th class="p-3 font-medium">{{ __('Importe') }}</th>
                            <th class="p-3 font-medium">{{ __('Estado') }}</th>
                            <th class="p-3 font-medium">{{ __('Vencimiento') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr class="border-b border-gray-100">
                                <td class="p-3"><a href="{{ route('invoices.show', $invoice) }}" class="underline">{{ $invoice->number }}</a></td>
                                <td class="p-3">
                                    {{ $invoice->user?->email ?? '—' }}
                                    @if ($invoice->user?->trashed())
                                        <span class="text-gray-500">({{ __('dado de baja') }})</span>
                                    @endif
                                </td>
                                <td class="p-3">{{ $invoice->period_start->format('d/m/Y') }} – {{ $invoice->period_end->format('d/m/Y') }}</td>
                                <td class="p-3">{{ number_format((float) $invoice->amount, 2, ',', '.') }} €</td>
                                <td class="p-3">{{ $invoice->status->label() }}</td>
                                <td class="p-3">{{ $invoice->due_at?->format('d/m/Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{ $invoices->links() }}
            @endif
        </section>
    </div>
</x-layouts::app>
