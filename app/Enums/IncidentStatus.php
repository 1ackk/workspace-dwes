<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Abierta'),
            self::InProgress => __('En curso'),
            self::Resolved => __('Resuelta'),
            self::Closed => __('Cerrada'),
        };
    }
}
