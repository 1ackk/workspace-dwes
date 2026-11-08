<?php

namespace App\Enums;

enum BookingStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => __('Pendiente de pago'),
            self::Confirmed => __('Confirmada'),
            self::Cancelled => __('Cancelada'),
            self::Expired => __('Caducada'),
            self::Completed => __('Completada'),
        };
    }

    /**
     * Estados que ocupan la franja de sus recursos (una pendiente solo mientras no caduque).
     *
     * @return list<self>
     */
    public static function blocking(): array
    {
        return [self::PendingPayment, self::Confirmed, self::Completed];
    }
}
