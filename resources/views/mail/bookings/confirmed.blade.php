<x-mail::message>
# {{ __('Reserva confirmada') }}

{{ __('Hola, :name:', ['name' => $booking->user->name]) }}

{{ __('Hemos recibido el pago y tu reserva del :date está confirmada.', ['date' => $booking->date->format('d/m/Y')]) }}

@include('mail.bookings._summary')

{{ __('Recuerda que solo puedes cancelar con la antelación mínima de cada espacio.') }}

{{ __('Gracias,') }}<br>
{{ config('app.name') }}
</x-mail::message>
