<?php

declare(strict_types=1);

namespace SPUI\Enum;

enum EstadoPantalla: string
{
    case Activo = 'activo';
    case Inactivo = 'inactivo';
    case Mantenimiento = 'mantenimiento';

    /** Texto para mostrar en el CMS (los value se guardan en minúscula). */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Activo        => 'Activo',
            self::Inactivo      => 'Inactivo',
            self::Mantenimiento => 'Mantenimiento',
        };
    }
}
