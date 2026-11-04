<?php

declare(strict_types=1);

namespace SPUI\Enum;

/**
 * De dónde salen las clases de un cronograma (noviembre 2026).
 *
 * Propio: tiene sus ítems, cargados a mano o desde el Excel.
 * Del edificio de la pantalla: no tiene ítems; en cada TV muestra el
 * cronograma propio vigente del edificio donde está ubicada esa pantalla, así
 * que una Pi que se muda de edificio cambia de cronograma sin tocar nada.
 */
enum OrigenCronograma: string
{
    case Propio   = 'propio';
    case Edificio = 'edificio';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Propio   => 'Propio',
            self::Edificio => 'Del edificio de la pantalla',
        };
    }
}
