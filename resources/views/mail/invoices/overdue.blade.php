<x-mail::message>
# {{ __('Factura vencida') }}

{{ __('Hola, :name:', ['name' => $invoice->user->name]) }}

{{ __('La factura :number venció el :date y todavía tiene saldo pendiente.', ['number' => $invoice->number, 'date' => $invoice->due_at?->format('d/m/Y')]) }}

<x-mail::table>
| {{ __('Factura') }} | {{ __('Vencimiento') }} | {{ __('Días de retraso') }} | {{ __('Saldo pendiente') }} |
|:--|:--|--:|--:|
| {{ $invoice->number }} | {{ $invoice->due_at?->format('d/m/Y') }} | {{ $daysLate }} | {{ number_format((float) $invoice->balance, 2, ',', '.') }} € |
</x-mail::table>

{{ __('Si ya has hecho el pago, ignora este aviso: puede tardar en reflejarse.') }}

{{ __('Gracias,') }}<br>
{{ config('app.name') }}
</x-mail::message>
