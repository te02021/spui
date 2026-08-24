<?php

declare(strict_types=1);

namespace SPUI\Enum;

enum TipoContenido: string
{
    case Texto      = 'texto';
    case Imagen     = 'imagen';
    case Video      = 'video';
    case Youtube    = 'youtube';
    case Qr         = 'qr';
    case Cronograma = 'cronograma';

    /**
     * Texto para mostrar en el CMS.
     * Se resuelve caso por caso y no con un |capitalize porque "youtube" y
     * "qr" no se escriben con una sola mayúscula inicial.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Texto      => 'Texto',
            self::Imagen     => 'Imagen',
            self::Video      => 'Video',
            self::Youtube    => 'YouTube',
            self::Qr         => 'QR',
            self::Cronograma => 'Cronograma',
        };
    }
}
