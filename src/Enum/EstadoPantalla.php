<?php

declare(strict_types=1);

namespace SPUI\Enum;

enum EstadoPantalla: string
{
    case Activo = 'activo';
    case Inactivo = 'inactivo';
    case Mantenimiento = 'mantenimiento';
}
