<?php

declare(strict_types=1);

namespace SPUI\Enum;

/**
 * Dos estados y nada más (29/10/2026, decisión de Mateo). Hubo un "borrador"
 * y un paso de "publicar": un contenido recién creado no se podía agregar a
 * una playlist hasta publicarlo, y eso confundía más de lo que protegía. Ahora
 * todo contenido está disponible apenas se crea, y archivarlo lo saca de las
 * pantallas en el acto (SyncController sólo manda los disponibles).
 */
enum EstadoContenido: string
{
    case Disponible = 'disponible';
    case Archivado = 'archivado';

    /** Texto para mostrar en el CMS (los value se guardan en minúscula). */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Archivado  => 'Archivado',
        };
    }
}
