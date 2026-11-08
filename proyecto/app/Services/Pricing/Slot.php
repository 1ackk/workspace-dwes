<?php

namespace App\Services\Pricing;

use App\Models\Resource;
use Carbon\CarbonInterface;

/**
 * Un recurso y la franja [start, end) en la que se quiere reservar.
 */
readonly class Slot
{
    public function __construct(
        public Resource $resource,
        public CarbonInterface $start,
        public CarbonInterface $end,
    ) {}
}
