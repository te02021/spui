<?php

declare(strict_types=1);

namespace SPUI\Enum;

/**
 * Cómo se presenta un contenido en el TV (noviembre 2026).
 *
 * Institucional: franja blanca arriba con el logo de UNRaf y la fecha, filete
 * con los colores del molino y pie con el nombre del sistema y la ubicación;
 * el contenido ocupa el resto. Sin plantilla: el contenido de borde a borde.
 */
enum PlantillaPantalla: string
{
    case Institucional = 'institucional';
    case Ninguna       = 'ninguna';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Institucional => 'Institucional',
            self::Ninguna       => 'Sin plantilla',
        };
    }

    /**
     * La que trae cada tipo al crearlo: texto, QR y cronograma con la
     * identificación de la universidad; imagen y video, de borde a borde.
     */
    public static function porDefecto(TipoContenido $tipo): self
    {
        return match ($tipo) {
            TipoContenido::Imagen, TipoContenido::Video => self::Ninguna,
            default                                     => self::Institucional,
        };
    }

    /**
     * Un video va siempre sin plantilla: VLC lo reproduce a pantalla completa
     * y no hay forma de encajarlo dentro del marco.
     */
    public static function admiteTipo(self $plantilla, TipoContenido $tipo): bool
    {
        return $plantilla === self::Ninguna || $tipo !== TipoContenido::Video;
    }
}
