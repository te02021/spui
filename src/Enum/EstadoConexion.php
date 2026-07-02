<?php

declare(strict_types=1);

namespace SPUI\Enum;

enum EstadoConexion: string
{
    case Conectado = 'conectado';
    case Desconectado = 'desconectado';
    case SinRegistrar = 'sin_registrar';
}
