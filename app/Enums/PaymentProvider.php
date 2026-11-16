<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case Simulated = 'simulated';
    case Paypal = 'paypal';
}
