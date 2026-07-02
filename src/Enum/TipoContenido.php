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
}
