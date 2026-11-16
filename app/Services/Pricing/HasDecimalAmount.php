<?php

namespace App\Services\Pricing;

/**
 * Los importes se calculan en céntimos enteros; este texto es el que se guarda o se muestra.
 */
trait HasDecimalAmount
{
    /**
     * El importe con dos decimales, por ejemplo "25.80".
     */
    public function decimal(): string
    {
        return sprintf('%d.%02d', intdiv($this->cents, 100), $this->cents % 100);
    }
}
