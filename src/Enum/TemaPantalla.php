<?php

declare(strict_types=1);

namespace SPUI\Enum;

/**
 * Colores de la plantilla en el TV (noviembre 2026): fondo claro u oscuro, o
 * automático, que es claro de día y oscuro a la noche. El horario del cambio
 * lo fijan spui_tema_claro_desde / spui_tema_oscuro_desde (services.yaml).
 */
enum TemaPantalla: string
{
    case Automatico = 'automatico';
    case Claro      = 'claro';
    case Oscuro     = 'oscuro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Automatico => 'Automático',
            self::Claro      => 'Claro',
            self::Oscuro     => 'Oscuro',
        };
    }
}
