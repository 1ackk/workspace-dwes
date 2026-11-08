<?php

namespace App\Enums;

enum BookingStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Completed = 'completed';

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
