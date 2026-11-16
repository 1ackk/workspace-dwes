<?php

namespace App\Enums;

enum RoleName: string
{
    case Member = 'usuario';
    case Reception = 'recepción';
    case SpaceManager = 'responsable de espacio';
    case Accountant = 'contable';
    case Admin = 'administrador';
}
