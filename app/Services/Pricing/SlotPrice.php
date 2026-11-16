<?php

namespace App\Services\Pricing;

/**
 * El precio de una franja y su desglose. La suma de los tramos es siempre igual a $cents.
 */
readonly class SlotPrice
{
    use HasDecimalAmount;

    /**
     * @param  list<PriceSegment>  $segments
     */
    public function __construct(
        public Slot $slot,
        public int $cents,
        public array $segments,
    ) {}
}
