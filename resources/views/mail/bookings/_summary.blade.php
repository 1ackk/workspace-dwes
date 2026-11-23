{{-- Resumen común de los correos de reservas: recursos, franjas, precios y total. --}}
{{-- Sin sangría: en Markdown, una línea sangrada se convierte en bloque de código. --}}
<x-mail::table>
| {{ __('Recurso') }} | {{ __('Espacio') }} | {{ __('Franja') }} | {{ __('Precio') }} |
|:--|:--|:--|--:|
@foreach ($booking->resources as $resource)
| {{ $resource->name }} | {{ $resource->space->name }} | {{ $resource->pivot->start_at->format('d/m/Y H:i') }} – {{ $resource->pivot->end_at->format('H:i') }} | {{ number_format((float) $resource->pivot->precio_final, 2, ',', '.') }} € |
@endforeach
</x-mail::table>

**{{ __('Total') }}: {{ number_format((float) $booking->total, 2, ',', '.') }} €**
