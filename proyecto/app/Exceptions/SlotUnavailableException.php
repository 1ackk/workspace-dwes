<?php

namespace App\Exceptions;

use App\Services\Pricing\Slot;

class SlotUnavailableException extends BookingException
{
    public function __construct(public readonly Slot $slot)
    {
        parent::__construct(__(':resource no está disponible el :date de :start a :end.', [
            'resource' => $slot->resource->name,
            'date' => $slot->start->format('d/m/Y'),
            'start' => $slot->start->format('H:i'),
            'end' => $slot->end->format('H:i'),
        ]));
    }
}
