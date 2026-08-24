<?php

declare(strict_types=1);

namespace SPUI\Enum;

enum EstadoContenido: string
{
    case Borrador = 'borrador';
    case Publicado = 'publicado';
    case Archivado = 'archivado';

    /** Texto para mostrar en el CMS (los value se guardan en minúscula). */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador  => 'Borrador',
            self::Publicado => 'Publicado',
            self::Archivado => 'Archivado',
        };
    }
}
