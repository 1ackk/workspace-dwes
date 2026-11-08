<?php

namespace App\Enums;

enum ResourceType: string
{
    case Desk = 'desk';
    case Room = 'room';
    case MeetingRoom = 'meeting_room';
    case Projector = 'projector';
}
