<?php

namespace App\Exceptions;

use App\Models\Resource;

class InvalidBookingRequestException extends BookingException
{
    public static function noSlots(): self
    {
        return new self(__('La reserva debe incluir al menos una franja.'));
    }

    public static function overlappingSlots(Resource $resource): self
    {
        return new self(__('Las franjas de :resource se solapan entre sí.', ['resource' => $resource->name]));
    }

    public static function inactiveResource(Resource $resource): self
    {
        return new self(__(':resource no se puede reservar porque no está activo.', ['resource' => $resource->name]));
    }
}
