<x-mail::message>
# {{ __('Reserva confirmada') }}

{{ __('Hola, :name:', ['name' => $booking->user->name]) }}

{{ __('Hemos recibido el pago y tu reserva del :date está confirmada.', ['date' => $booking->date->format('d/m/Y')]) }}

<x-mail::table>
| {{ __('Recurso') }} | {{ __('Espacio') }} | {{ __('Franja') }} | {{ __('Precio') }} |
|:--|:--|:--|--:|
@foreach ($booking->resources as $resource)
| {{ $resource->name }} | {{ $resource->space->name }} | {{ $resource->pivot->start_at->format('d/m/Y H:i') }} – {{ $resource->pivot->end_at->format('H:i') }} | {{ number_format((float) $resource->pivot->precio_final, 2, ',', '.') }} € |
@endforeach
</x-mail::table>

**{{ __('Total') }}: {{ number_format((float) $booking->total, 2, ',', '.') }} €**

{{ __('Recuerda que solo puedes cancelar con la antelación mínima de cada espacio.') }}

{{ __('Gracias,') }}<br>
{{ config('app.name') }}
</x-mail::message>
