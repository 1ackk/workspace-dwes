<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pendiente'),
            self::Completed => __('Completado'),
            self::Failed => __('Fallido'),
            self::Refunded => __('Reembolsado'),
        };
    }
}
