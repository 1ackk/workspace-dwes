<?php

namespace App\Services\Invoicing;

use RuntimeException;

/**
 * El UPDATE que asigna la factura no cambió exactamente las reservas bloqueadas. Se lanza dentro de
 * la transacción del usuario para deshacerla entera: nunca queda una factura cuyo importe no
 * corresponda con sus reservas.
 */
class BookingsChangedException extends RuntimeException
{
    public static function for(int $userId, int $expected, int $changed): self
    {
        return new self(__('Las reservas del usuario :user cambiaron mientras se facturaban (:changed de :expected). No se ha creado su factura.', [
            'user' => $userId,
            'changed' => $changed,
            'expected' => $expected,
        ]));
    }
}
