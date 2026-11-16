<?php

namespace App\Enums;

enum TariffKind: string
{
    case Base = 'base';
    case Afternoon = 'afternoon';
    case Weekend = 'weekend';
}
