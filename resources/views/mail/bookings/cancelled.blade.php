<x-mail::message>
# {{ __('Reserva cancelada') }}

{{ __('Hola, :name:', ['name' => $booking->user->name]) }}

{{ __('Tu reserva del :date se ha cancelado y sus franjas han quedado libres.', ['date' => $booking->date->format('d/m/Y')]) }}

@include('mail.bookings._summary')

{{ __('Si no has pedido esta cancelación, ponte en contacto con recepción.') }}

{{ __('Gracias,') }}<br>
{{ config('app.name') }}
</x-mail::message>
