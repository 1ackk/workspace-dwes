<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Días para pagar una factura
    |--------------------------------------------------------------------------
    |
    | invoices:generate fija el vencimiento de cada factura en el último día del
    | periodo facturado más estos días.
    |
    */

    'due_days' => (int) env('INVOICE_DUE_DAYS', 15),

];
