<?php

namespace App\Services\Pricing;

/**
 * El precio de varias franjas: el total es la suma de las franjas ya redondeadas.
 */
readonly class BookingPrice
{
    use HasDecimalAmount;

    public int $cents;

    /**
     * @param  list<SlotPrice>  $slots
     */
    public function __construct(public array $slots)
    {
        $this->cents = array_sum(array_map(fn (SlotPrice $price) => $price->cents, $slots));
    }
}
