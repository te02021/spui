<?php

declare(strict_types=1);

namespace SPUI\Enum;

enum TipoContenido: string
{
    case Texto      = 'texto';
    case Imagen     = 'imagen';
    case Video      = 'video';
    // Hubo un 'youtube' hasta el 28/10/2026: VLC no abre el link y YouTube
    // frena las descargas desde la Pi ("confirmá que no sos un bot"). Un video
    // de YouTube se baja desde la PC y se sube como video. Ver docs/06 §5.
    case Qr         = 'qr';
    case Cronograma = 'cronograma';

    /**
     * Texto para mostrar en el CMS.
     * Se resuelve caso por caso y no con un |capitalize porque "qr" no se
     * escribe con una sola mayúscula inicial.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Texto      => 'Texto',
            self::Imagen     => 'Imagen',
            self::Video      => 'Video',
            self::Qr         => 'QR',
            self::Cronograma => 'Cronograma',
        };
    }
}
