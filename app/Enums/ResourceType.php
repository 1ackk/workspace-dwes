<?php

namespace App\Enums;

enum ResourceType: string
{
    case Desk = 'desk';
    case Room = 'room';
    case MeetingRoom = 'meeting_room';
    case Projector = 'projector';

    public function label(): string
    {
        return match ($this) {
            self::Desk => __('Puesto'),
            self::Room => __('Sala'),
            self::MeetingRoom => __('Sala de reuniones'),
            self::Projector => __('Proyector'),
        };
    }
}
