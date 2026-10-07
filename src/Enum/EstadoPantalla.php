<?php

declare(strict_types=1);

namespace SPUI\Enum;

/**
 * Estado de una pantalla: activa o inactiva, nada más.
 *
 * Hasta octubre 2026 había un tercer valor, "mantenimiento", que no hacía
 * nada distinto de "inactivo" (ni siquiera eso: ningún estado afectaba lo que
 * mostraba la pantalla) y que el botón del listado no sabía cómo alternar.
 * Se eliminó; Version20261007120000 pasa los que quedaban a inactivo. En el
 * CMS se maneja con un switch (Pantalla::isActiva / setActiva).
 */
enum EstadoPantalla: string
{
    case Activo = 'activo';
    case Inactivo = 'inactivo';

    /** Texto para mostrar en el CMS (los value se guardan en minúscula). */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Activo   => 'Activa',
            self::Inactivo => 'Inactiva',
        };
    }
}
