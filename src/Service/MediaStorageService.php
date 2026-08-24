<?php

declare(strict_types=1);

namespace SPUI\Service;

use Psr\Log\LoggerInterface;
use Shared\Service\S3StorageService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Almacenamiento de los archivos de contenido (imágenes, videos, PNG de QR).
 *
 * Abstrae dónde viven los archivos para que el resto del código no tenga que
 * saberlo. Dos backends:
 *
 *   - 'local' (default): disco, en public/uploads/spui. Es lo que venía
 *     haciendo el sistema y sigue siendo el modo cómodo para desarrollo.
 *   - 's3': el bucket S3 de la intranet, vía Shared\Service\S3StorageService
 *     (el mismo que usa viáticos). En producción casi no se usa disco local.
 *
 * Se elige con SPUI_STORAGE_DRIVER. El default es 'local' a propósito: nadie
 * tiene que tocar nada para que el entorno actual siga funcionando igual.
 *
 * La Raspberry Pi nunca ve esta diferencia: siempre descarga de
 * /api/spui/media/{filename} y MediaController resuelve de dónde sale el
 * archivo. Así el nombre que ve el Pi es estable y su caché por nombre +
 * verificación SHA-256 siguen funcionando igual con cualquier backend.
 */
final class MediaStorageService
{
    public const DRIVER_LOCAL = 'local';
    public const DRIVER_S3    = 's3';

    /** Prefijo de las claves en S3 (equivale a la carpeta local). */
    private const S3_PREFIX = 'spui/media/';

    /** Tipos MIME aceptados. El sistema sólo reproduce imágenes y videos. */
    private const MIME_PERMITIDOS = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
    ];

    /** Tamaño máximo por archivo (bytes). Los videos institucionales entran holgados. */
    private const TAMANIO_MAXIMO = 200 * 1024 * 1024;   // 200 MB

    public function __construct(
        private readonly S3StorageService $s3,
        private readonly SluggerInterface $slugger,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/public/uploads/spui')]
        private readonly string $uploadDir,
        #[Autowire('%env(default:spui_storage_driver_default:SPUI_STORAGE_DRIVER)%')]
        private readonly string $driver = self::DRIVER_LOCAL,
    ) {}

    public function usaS3(): bool
    {
        return $this->driver === self::DRIVER_S3;
    }

    /**
     * Valida tipo y tamaño antes de guardar nada.
     *
     * @return string|null Mensaje de error en español, o null si el archivo sirve.
     */
    public function validar(UploadedFile $archivo): ?string
    {
        if (!$archivo->isValid()) {
            return 'El archivo no se subió correctamente. Probá de nuevo.';
        }

        if ($archivo->getSize() > self::TAMANIO_MAXIMO) {
            return sprintf(
                'El archivo pesa %s y el máximo es %s.',
                $this->formatearTamanio((int) $archivo->getSize()),
                $this->formatearTamanio(self::TAMANIO_MAXIMO),
            );
        }

        $mime = $archivo->getMimeType();
        if (!in_array($mime, self::MIME_PERMITIDOS, true)) {
            return 'Formato no admitido. Se aceptan imágenes (JPG, PNG, GIF, WEBP) y videos (MP4, WEBM, OGG, MOV).';
        }

        return null;
    }

    /**
     * Guarda un archivo subido y devuelve la ruta relativa a persistir en
     * Contenido::rutaArchivo (siempre con el prefijo 'uploads/spui/', sea cual
     * sea el backend, para no tener que migrar los registros existentes).
     */
    public function guardar(UploadedFile $archivo): string
    {
        $nombre = $this->nombreUnico($archivo);

        if ($this->usaS3()) {
            $this->s3->upload($archivo, self::S3_PREFIX . $nombre);
            $this->logger->info('SPUI: archivo subido a S3.', ['archivo' => $nombre]);
        } else {
            $archivo->move($this->uploadDir, $nombre);
        }

        return 'uploads/spui/' . $nombre;
    }

    /**
     * Guarda contenido ya generado en memoria (el PNG de los QR, que no viene
     * de un upload sino de QrGeneratorService).
     */
    public function guardarContenido(string $binario, string $nombre): string
    {
        if ($this->usaS3()) {
            $tmp = tempnam(sys_get_temp_dir(), 'spui_');
            file_put_contents($tmp, $binario);
            try {
                // UploadedFile en modo test: envuelve un archivo que no vino de
                // una petición HTTP. Es la misma técnica que usa viáticos para
                // resubir los PDF que genera.
                $this->s3->upload(
                    new UploadedFile($tmp, $nombre, 'image/png', null, true),
                    self::S3_PREFIX . $nombre,
                );
            } finally {
                @unlink($tmp);
            }
        } else {
            if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
                throw new FileException('No se pudo crear el directorio de subidas: ' . $this->uploadDir);
            }
            file_put_contents($this->uploadDir . '/' . $nombre, $binario);
        }

        return 'uploads/spui/' . $nombre;
    }

    /**
     * Lee un archivo por su nombre. Devuelve null si no existe.
     * Lo usa MediaController para servírselo al Pi.
     */
    public function leer(string $nombre): ?string
    {
        $nombre = basename($nombre);   // corta cualquier intento de path traversal

        if ($this->usaS3()) {
            try {
                // Se pide con URL prefirmada de vida corta: la firma no sale de
                // acá, el Pi nunca la ve.
                $url = $this->s3->getPresignedUrl(self::S3_PREFIX . $nombre, 60);
                $contenido = @file_get_contents($url);
                return $contenido === false ? null : $contenido;
            } catch (\Throwable $e) {
                $this->logger->warning('SPUI: no se pudo leer de S3: {msg}', [
                    'msg' => $e->getMessage(), 'archivo' => $nombre,
                ]);
                return null;
            }
        }

        $ruta = $this->uploadDir . '/' . $nombre;
        if (!is_file($ruta) || !is_readable($ruta)) {
            return null;
        }

        $contenido = @file_get_contents($ruta);
        return $contenido === false ? null : $contenido;
    }

    /** Ruta absoluta en disco, o null si el backend no es local. */
    public function rutaLocal(string $nombre): ?string
    {
        if ($this->usaS3()) {
            return null;
        }

        $ruta = $this->uploadDir . '/' . basename($nombre);
        return is_file($ruta) && is_readable($ruta) ? $ruta : null;
    }

    /**
     * Borra un archivo a partir de la ruta guardada en Contenido::rutaArchivo.
     * No falla si el archivo ya no está: el objetivo es que no quede huérfano.
     */
    public function borrar(?string $rutaRelativa): void
    {
        if ($rutaRelativa === null || !str_starts_with($rutaRelativa, 'uploads/spui/')) {
            return;
        }

        $nombre = basename($rutaRelativa);

        try {
            if ($this->usaS3()) {
                $this->s3->delete(self::S3_PREFIX . $nombre);
            } else {
                $ruta = $this->uploadDir . '/' . $nombre;
                if (is_file($ruta)) {
                    @unlink($ruta);
                }
            }
            $this->logger->info('SPUI: archivo eliminado.', ['archivo' => $nombre]);
        } catch (\Throwable $e) {
            // Que no se pueda borrar el archivo no debe impedir borrar el
            // registro: se registra y se sigue.
            $this->logger->warning('SPUI: no se pudo eliminar el archivo: {msg}', [
                'msg' => $e->getMessage(), 'archivo' => $nombre,
            ]);
        }
    }

    /**
     * SHA-256 del archivo guardado. El Pi lo usa para validar la descarga y
     * para saber si ya lo tiene en su caché.
     */
    public function hash(string $rutaRelativa): ?string
    {
        $nombre = basename($rutaRelativa);

        if (!$this->usaS3()) {
            $ruta = $this->uploadDir . '/' . $nombre;
            return is_file($ruta) ? hash_file('sha256', $ruta) : null;
        }

        $contenido = $this->leer($nombre);
        return $contenido === null ? null : hash('sha256', $contenido);
    }

    // -------------------------------------------------------------------------

    /** Nombre único, legible y sin caracteres problemáticos. */
    private function nombreUnico(UploadedFile $archivo): string
    {
        $extension = $archivo->guessExtension() ?: $archivo->getClientOriginalExtension();
        $base      = pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME);

        return $this->slugger->slug($base) . '-' . uniqid() . '.' . $extension;
    }

    private function formatearTamanio(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }
        return round($bytes / 1024) . ' KB';
    }
}
