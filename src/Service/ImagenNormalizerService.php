<?php

declare(strict_types=1);

namespace SPUI\Service;

use Psr\Log\LoggerInterface;

/**
 * Deja cada imagen como la puede mostrar la Raspberry Pi: girada según la
 * foto y, como mucho, de 1920×1080.
 *
 * Por qué existe: una foto de celular de 4608×3456 girada por EXIF (la
 * orientación que guarda la cámara) hacía que VLC se cerrara en la Pi con
 * "double free detected" apenas la mostraba, y el reproductor entraba en un
 * bucle de reinicios (24/10/2026). Las imágenes no se rechazan como los
 * videos: quien carga contenido sube fotos de celular todo el tiempo, y
 * achicarlas no pierde nada que la pantalla pueda mostrar.
 *
 * Qué hace:
 *   - Aplica la orientación EXIF a los píxeles y no deja metadatos: la Pi
 *     recibe la imagen ya derecha y no tiene que girar nada.
 *   - La achica para que entre en 1920 × 1080, el mismo límite que los videos
 *     (en cualquier orientación: una vertical queda en 1080 × 1440 o menos).
 *   - La guarda como JPEG (calidad 90) o, si tiene transparencia, como PNG.
 *     WebP y GIF pasan a uno de esos dos, que VLC decodifica siempre.
 *   - Si ya cumple todo (JPEG o PNG, derecha, de 1920 × 1080 o menos), no la
 *     toca: no se recomprime una imagen que ya sirve.
 */
final class ImagenNormalizerService
{
    public const LADO_MAYOR_MAXIMO = 1920;
    public const LADO_MENOR_MAXIMO = 1080;

    /** Más que esto no se intenta abrir: lo descomprimido no entra en la memoria de PHP. */
    private const MEGAPIXELES_MAXIMOS = 50;

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @return array{ruta: string, extension: string, mime: string, ancho: int, alto: int, cambio: bool}|array{error: string}
     *         'ruta' es el archivo listo para guardar (el original si no hizo falta tocarlo).
     */
    public function normalizar(string $ruta): array
    {
        $info = @getimagesize($ruta);
        if ($info === false) {
            return ['error' => 'No se pudo leer la imagen. Probá guardarla de nuevo como JPG o PNG y volvé a subirla.'];
        }
        [$ancho, $alto, $tipo] = $info;

        if ($ancho * $alto > self::MEGAPIXELES_MAXIMOS * 1_000_000) {
            return ['error' => sprintf(
                'La imagen es de %d×%d, demasiado grande para procesarla. Achicala a 1920×1080 y volvé a subirla.',
                $ancho, $alto,
            )];
        }

        $orientacion = $tipo === IMAGETYPE_JPEG ? $this->orientacion($ruta) : 1;
        $gira = in_array($orientacion, [5, 6, 7, 8], true);
        [$anchoFinal, $altoFinal] = $gira ? [$alto, $ancho] : [$ancho, $alto];
        $escala = $this->escala($anchoFinal, $altoFinal);

        $sirveComoEsta = in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) && $orientacion === 1 && $escala >= 1.0;
        if ($sirveComoEsta) {
            $ext = $tipo === IMAGETYPE_PNG ? 'png' : 'jpg';
            return ['ruta' => $ruta, 'extension' => $ext, 'mime' => $ext === 'png' ? 'image/png' : 'image/jpeg',
                    'ancho' => $ancho, 'alto' => $alto, 'cambio' => false];
        }

        $img = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($ruta),
            IMAGETYPE_PNG  => @imagecreatefrompng($ruta),
            IMAGETYPE_WEBP => @imagecreatefromwebp($ruta),
            IMAGETYPE_GIF  => @imagecreatefromgif($ruta),
            default        => false,
        };
        if ($img === false) {
            return ['error' => 'No se pudo leer la imagen. Probá guardarla de nuevo como JPG o PNG y volvé a subirla.'];
        }

        try {
            $img = $this->orientar($img, $orientacion);

            if ($escala < 1.0) {
                $nuevoAncho = max(1, (int) round(imagesx($img) * $escala));
                $nuevoAlto  = max(1, (int) round(imagesy($img) * $escala));
                $chica = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
                imagealphablending($chica, false);
                imagesavealpha($chica, true);
                imagecopyresampled($chica, $img, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, imagesx($img), imagesy($img));
                imagedestroy($img);
                $img = $chica;
            }

            $conTransparencia = $tipo !== IMAGETYPE_JPEG && $this->tieneTransparencia($img);
            $salida = tempnam(sys_get_temp_dir(), 'spui_img_');
            if ($conTransparencia) {
                imagesavealpha($img, true);
                $ok = imagepng($img, $salida, 6);
                $ext = 'png';
            } else {
                $ok = imagejpeg($img, $salida, 90);
                $ext = 'jpg';
            }
            $w = imagesx($img);
            $h = imagesy($img);
        } finally {
            if ($img instanceof \GdImage) {
                imagedestroy($img);
            }
        }

        if (!$ok) {
            $this->logger->error('SPUI: no se pudo escribir la imagen adaptada.', ['origen' => $ruta]);
            return ['error' => 'No se pudo procesar la imagen. Probá de nuevo o avisá al administrador si el problema sigue.'];
        }

        return ['ruta' => $salida, 'extension' => $ext, 'mime' => $ext === 'png' ? 'image/png' : 'image/jpeg',
                'ancho' => $w, 'alto' => $h, 'cambio' => true];
    }

    /** Cuánto hay que achicar para entrar en 1920 × 1080 (en cualquier orientación); 1.0 o más = nada. */
    private function escala(int $ancho, int $alto): float
    {
        $mayor = max($ancho, $alto);
        $menor = min($ancho, $alto);
        return min(1.0, self::LADO_MAYOR_MAXIMO / $mayor, self::LADO_MENOR_MAXIMO / $menor);
    }

    private function orientacion(string $ruta): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($ruta);
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        return $o >= 1 && $o <= 8 ? $o : 1;
    }

    /** Aplica a los píxeles la orientación EXIF (1 = derecha … 8), como la mostraría un visor de fotos. */
    private function orientar(\GdImage $img, int $o): \GdImage
    {
        if (in_array($o, [2, 4, 5, 7], true)) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }
        // imagerotate gira en sentido antihorario: -90 = 90° horario. Con el
        // espejo previo, 5 queda como la transpuesta y 7 como la transversa.
        $grados = match ($o) {
            3, 4 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };
        if ($grados === 0) {
            return $img;
        }
        $girada = imagerotate($img, $grados, 0);
        if ($girada === false) {
            return $img;
        }
        imagedestroy($img);
        return $girada;
    }

    /** ¿Algún píxel no es opaco? Se mira una grilla, no los millones de píxeles. */
    private function tieneTransparencia(\GdImage $img): bool
    {
        if (!imageistruecolor($img)) {
            return imagecolortransparent($img) !== -1;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $paso = max(1, (int) floor(min($w, $h) / 64));
        for ($y = 0; $y < $h; $y += $paso) {
            for ($x = 0; $x < $w; $x += $paso) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }
        return false;
    }
}
