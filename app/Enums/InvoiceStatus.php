<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pendiente'),
            self::Paid => __('Pagada'),
            self::Void => __('Anulada'),
        };
    }
}
