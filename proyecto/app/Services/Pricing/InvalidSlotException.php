<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

class InvalidSlotException extends InvalidArgumentException
{
    public static function endNotAfterStart(): self
    {
        return new self(__('El fin de la franja debe ser posterior a su inicio.'));
    }

    public static function crossesMidnight(): self
    {
        return new self(__('La franja debe empezar y terminar el mismo día.'));
    }

    public static function notWholeMinutes(): self
    {
        return new self(__('La franja debe indicarse en minutos exactos, sin segundos.'));
    }
}
