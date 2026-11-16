<?php

namespace App\Services\Pricing;

use App\Exceptions\BookingException;
use App\Models\Resource;

class InvalidSlotException extends BookingException
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

    public static function offGrid(): self
    {
        return new self(__('Las franjas deben empezar y terminar en punto o a y media.'));
    }

    public static function inThePast(): self
    {
        return new self(__('La franja debe empezar después del momento actual.'));
    }

    public static function outsideOpeningHours(Resource $resource): self
    {
        return new self(__(':resource solo se puede reservar de :opens a :closes.', [
            'resource' => $resource->name,
            'opens' => substr($resource->space->opens_at, 0, 5),
            'closes' => substr($resource->space->closes_at, 0, 5),
        ]));
    }
}
