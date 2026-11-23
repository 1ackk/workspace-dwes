<x-mail::message>
# {{ __('Tu reserva empieza pronto') }}

{{ __('Hola, :name:', ['name' => $booking->user->name]) }}

@if ($hoursLeft === 1)
{{ __('Tienes una reserva confirmada que empieza en 1 hora, el :date a las :time.', ['date' => $startsAt->format('d/m/Y'), 'time' => $startsAt->format('H:i')]) }}
@else
{{ __('Tienes una reserva confirmada que empieza en :hours horas, el :date a las :time.', ['hours' => $hoursLeft, 'date' => $startsAt->format('d/m/Y'), 'time' => $startsAt->format('H:i')]) }}
@endif

@include('mail.bookings._summary')

{{ __('Si ya no puedes venir, cancélala cuanto antes para liberar las franjas, siempre dentro de la antelación mínima del espacio.') }}

{{ __('Gracias,') }}<br>
{{ config('app.name') }}
</x-mail::message>
