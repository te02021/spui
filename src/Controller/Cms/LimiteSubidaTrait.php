<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Symfony\Component\HttpFoundation\Request;

/**
 * Detecta cuando PHP descartó el body ENTERO de un POST multipart por
 * superar post_max_size.
 *
 * Cuando eso pasa, $_POST y $_FILES quedan vacíos sin ningún aviso — PHP no
 * expone el error de otra forma. El síntoma es engañoso: un formulario con
 * el título bien tipeado tira "El título es requerido", porque el body
 * completo nunca llegó a leerse, no porque el campo viniera vacío de
 * verdad. Pasó en producción: una imagen de varios MB (post_max_size
 * estaba en 8M, muy por debajo de lo que la app declara soportar) rompía
 * la edición de un Contenido con ese mensaje sin ninguna relación con la
 * causa real.
 *
 * Se compara Content-Length contra post_max_size directamente — es la
 * única señal confiable disponible del lado de la aplicación.
 */
trait LimiteSubidaTrait
{
    /** @return string|null Mensaje de error si el POST se descartó por tamaño, null si no aplica. */
    private function excedioLimiteSubida(Request $request): ?string
    {
        $contentLength = (int) $request->server->get('CONTENT_LENGTH', 0);
        if ($contentLength <= 0) {
            return null;
        }

        $limite = self::bytesDesdeIni((string) ini_get('post_max_size'));
        if ($limite <= 0 || $contentLength <= $limite) {
            return null;
        }

        return sprintf(
            'El archivo es demasiado grande para el servidor (máximo %s). Probá con uno más liviano.',
            self::formatearBytes($limite),
        );
    }

    /**
     * El tamaño máximo que de verdad puede tener un archivo subido: el menor
     * entre el máximo de la aplicación y los dos límites de PHP.
     *
     * Va al navegador (data-spui-max-bytes, validacion-vivo.js) para avisar
     * debajo del campo apenas se elige un archivo demasiado pesado, en vez de
     * mandarlo y que PHP descarte el pedido entero.
     */
    private function limiteArchivoBytes(int $maximoAplicacion): int
    {
        $limites = array_filter([
            $maximoAplicacion,
            self::bytesDesdeIni((string) ini_get('upload_max_filesize')),
            self::bytesDesdeIni((string) ini_get('post_max_size')),
        ], fn(int $b) => $b > 0);

        return $limites === [] ? $maximoAplicacion : min($limites);
    }

    private static function bytesDesdeIni(string $valor): int
    {
        $valor = trim($valor);
        if ($valor === '') {
            return 0;
        }

        $unidad = strtolower(substr($valor, -1));
        $numero = (int) $valor;

        return match ($unidad) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }

    private static function formatearBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return str_replace('.', ',', (string) round($bytes / (1024 * 1024 * 1024), 1)) . ' GB';
        }

        return round($bytes / (1024 * 1024)) . ' MB';
    }
}
