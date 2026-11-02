<?php

declare(strict_types=1);

namespace SPUI\Enum;

/**
 * Qué es cada renglón de un cronograma (noviembre 2026).
 *
 * Clase y actividad se repiten los días de la semana marcados; el evento
 * especial ocurre una sola vez, en su fecha.
 */
enum TipoItemCronograma: string
{
    case Clase     = 'clase';
    case Actividad = 'actividad';
    case Evento    = 'evento';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Clase     => 'Clase',
            self::Actividad => 'Actividad',
            self::Evento    => 'Evento especial',
        };
    }

    public function tieneFecha(): bool
    {
        return $this === self::Evento;
    }
}
