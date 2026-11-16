<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retención de una reserva pendiente de pago
    |--------------------------------------------------------------------------
    |
    | Minutos que una reserva pendiente de pago mantiene ocupadas sus franjas.
    |
    */

    'hold_minutes' => (int) env('BOOKING_HOLD_MINUTES', 15),

];
