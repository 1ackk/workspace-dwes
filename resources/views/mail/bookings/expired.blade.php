<x-mail::message>
# {{ __('Reserva caducada') }}

{{ __('Hola, :name:', ['name' => $booking->user->name]) }}

{{ __('El tiempo para pagar tu reserva del :date terminó sin recibir el pago. La reserva ha caducado y sus franjas han quedado libres para otros usuarios.', ['date' => $booking->date->format('d/m/Y')]) }}

@include('mail.bookings._summary')

{{ __('Si todavía te interesa, puedes hacer una reserva nueva mientras las franjas sigan libres.') }}

{{ __('Gracias,') }}<br>
{{ config('app.name') }}
</x-mail::message>
