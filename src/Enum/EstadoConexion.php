<?php

declare(strict_types=1);

namespace SPUI\Enum;

enum EstadoConexion: string
{
    case Conectado = 'conectado';
    case Desconectado = 'desconectado';
    case SinRegistrar = 'sin_registrar';

    /**
     * Texto para mostrar en el CMS.
     * Los value van en minúscula y con guión bajo porque así se guardan en la
     * base; en pantalla queremos "Sin registrar", no "sin_registrar" ni el
     * "Sin_registrar" que daría un |capitalize de Twig.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Conectado    => 'Conectado',
            self::Desconectado => 'Desconectado',
            self::SinRegistrar => 'Sin registrar',
        };
    }
}
