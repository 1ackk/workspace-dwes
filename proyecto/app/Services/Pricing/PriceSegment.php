<?php

namespace App\Services\Pricing;

use Carbon\CarbonInterface;

/**
 * Un tramo de la franja con un precio por hora y un recargo constantes.
 */
readonly class PriceSegment
{
    use HasDecimalAmount;

    public function __construct(
        public CarbonInterface $start,
        public CarbonInterface $end,
        public int $baseCentsPerHour,
        public int $surchargeBasisPoints,
        public int $cents,
    ) {}
}
