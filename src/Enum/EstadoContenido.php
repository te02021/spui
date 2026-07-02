<?php

declare(strict_types=1);

namespace SPUI\Enum;

enum EstadoContenido: string
{
    case Borrador = 'borrador';
    case Publicado = 'publicado';
    case Archivado = 'archivado';
}
