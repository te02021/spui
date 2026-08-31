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
 * Almacenamiento de los archivos de contenido (imágenes, videos, PNG de QR,
 * sonidos de alerta).
 *
 * Abstrae dónde viven los archivos para que el resto del código no tenga que
 * saberlo. Dos backends:
 *
 *   - 's3' (default desde agosto 2026): el bucket S3 de la intranet, vía
 *     Shared\Service\S3StorageService (el mismo que usa viáticos), bajo
 *     spui/media/<categoria>/. Es el modo esperado, incluso en desarrollo.
 *   - 'local': disco, en public/uploads/spui/<categoria>/. Sigue soportado
 *     como resguardo (por ejemplo si el S3 de la intranet no está
 *     disponible), no como el modo normal.
 *
 * Se elige con SPUI_STORAGE_DRIVER (default en spui_storage_driver_default,
 * services.yaml). Cada archivo va dentro de una subcarpeta por categoría
 * (self::CATEGORIAS) en vez de todos mezclados en una sola carpeta plana —
 * guardar()/guardarContenido() la piden, y toda ruta relativa que se persiste
 * en una entidad la lleva codificada ('uploads/spui/<categoria>/<nombre>').
 *
 * La Raspberry Pi nunca ve esta diferencia: siempre descarga de
 * /api/spui/media/{filename} (donde {filename} es 'categoria/nombre') y
 * MediaController resuelve de dónde sale el archivo. Así el nombre que ve el
 * Pi es estable y su caché por nombre + verificación SHA-256 siguen
 * funcionando igual con cualquier backend.
 */
final class MediaStorageService
{
    public const DRIVER_LOCAL = 'local';
    public const DRIVER_S3    = 's3';

    /** Prefijo de las claves en S3 (equivale a la carpeta local). */
    private const S3_PREFIX = 'spui/media/';

    /**
     * Subcarpetas válidas dentro de spui/media/ — imágenes, videos, QR y
     * sonidos de alerta separados, en vez de todo mezclado en una sola
     * carpeta plana. Es la única lista que sabe qué categorías existen;
     * agregar una nueva pasa por acá.
     */
    private const CATEGORIAS = ['imagenes', 'videos', 'qr', 'alertas'];

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
            // getErrorMessage() es útil para el log (dice justo qué directiva
            // de php.ini se pisó y con qué límite) pero es texto técnico en
            // inglés — no va al frontend. Acá se separan los dos: el detalle
            // técnico queda en el log, el usuario recibe un mensaje en
            // español que no expone configuración del servidor.
            $this->logger->warning('SPUI: subida de archivo rechazada por PHP.', [
                'archivo_original' => $archivo->getClientOriginalName(),
                'error_php'        => $archivo->getError(),
                'detalle'          => $archivo->getErrorMessage(),
            ]);

            return match ($archivo->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'El archivo es demasiado pesado para que lo acepte el servidor. Probá con uno más liviano o avisá al administrador.',
                UPLOAD_ERR_PARTIAL =>
                    'La subida se interrumpió a mitad de camino. Probá de nuevo.',
                UPLOAD_ERR_NO_FILE =>
                    'No se recibió ningún archivo. Probá de nuevo.',
                default =>
                    'El archivo no se pudo subir. Probá de nuevo o avisá al administrador si el problema sigue.',
            };
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
     * Guarda un archivo subido bajo una categoría (subcarpeta) y devuelve la
     * ruta relativa a persistir en Contenido::rutaArchivo /
     * AlertaEmergencia::sonidoArchivo — siempre con el formato
     * 'uploads/spui/<categoria>/<nombre>', sea cual sea el backend.
     *
     * @param string $categoria Una de self::CATEGORIAS ('imagenes', 'videos',
     *                          'qr', 'alertas'). Separa los archivos por tipo
     *                          dentro de spui/media/ en vez de mezclarlos todos
     *                          en una sola carpeta plana.
     */
    public function guardar(UploadedFile $archivo, string $categoria): string
    {
        $clave = $this->claveNueva($categoria, $this->nombreUnico($archivo));

        if ($this->usaS3()) {
            $this->s3->upload($archivo, self::S3_PREFIX . $clave);
            $this->logger->info('SPUI: archivo subido a S3.', ['archivo' => $clave]);
        } else {
            $archivo->move($this->dirLocalDe($categoria), basename($clave));
        }

        return 'uploads/spui/' . $clave;
    }

    /**
     * Guarda contenido ya generado en memoria (el PNG de los QR, que no viene
     * de un upload sino de QrGeneratorService) bajo una categoría.
     */
    public function guardarContenido(string $binario, string $nombre, string $categoria): string
    {
        $clave = $this->claveNueva($categoria, $nombre);

        if ($this->usaS3()) {
            $tmp = tempnam(sys_get_temp_dir(), 'spui_');
            file_put_contents($tmp, $binario);
            try {
                // UploadedFile en modo test: envuelve un archivo que no vino de
                // una petición HTTP. Es la misma técnica que usa viáticos para
                // resubir los PDF que genera.
                $this->s3->upload(
                    new UploadedFile($tmp, $nombre, 'image/png', null, true),
                    self::S3_PREFIX . $clave,
                );
            } finally {
                @unlink($tmp);
            }
        } else {
            file_put_contents($this->dirLocalDe($categoria) . '/' . $nombre, $binario);
        }

        return 'uploads/spui/' . $clave;
    }

    /**
     * Lee un archivo a partir de la ruta relativa guardada en la entidad.
     * Devuelve null si no existe o si la ruta no tiene una categoría válida.
     * Lo usa MediaController para servírselo al Pi.
     */
    public function leer(string $rutaRelativa): ?string
    {
        $clave = $this->claveDesdeRuta($rutaRelativa);
        if ($clave === null) {
            return null;
        }

        if ($this->usaS3()) {
            try {
                // Se pide con URL prefirmada de vida corta: la firma no sale de
                // acá, el Pi nunca la ve.
                $url = $this->s3->getPresignedUrl(self::S3_PREFIX . $clave, 60);
                $contenido = @file_get_contents($url);
                return $contenido === false ? null : $contenido;
            } catch (\Throwable $e) {
                $this->logger->warning('SPUI: no se pudo leer de S3: {msg}', [
                    'msg' => $e->getMessage(), 'archivo' => $clave,
                ]);
                return null;
            }
        }

        $ruta = $this->uploadDir . '/' . $clave;
        if (!is_file($ruta) || !is_readable($ruta)) {
            return null;
        }

        $contenido = @file_get_contents($ruta);
        return $contenido === false ? null : $contenido;
    }

    /** Ruta absoluta en disco, o null si el backend no es local o la ruta es inválida. */
    public function rutaLocal(string $rutaRelativa): ?string
    {
        if ($this->usaS3()) {
            return null;
        }

        $clave = $this->claveDesdeRuta($rutaRelativa);
        if ($clave === null) {
            return null;
        }

        $ruta = $this->uploadDir . '/' . $clave;
        return is_file($ruta) && is_readable($ruta) ? $ruta : null;
    }

    /**
     * Borra un archivo a partir de la ruta guardada en Contenido::rutaArchivo
     * o AlertaEmergencia::sonidoArchivo. No falla si el archivo ya no está o
     * la ruta es inválida: el objetivo es que no quede huérfano, no reventar.
     */
    public function borrar(?string $rutaRelativa): void
    {
        if ($rutaRelativa === null) {
            return;
        }

        $clave = $this->claveDesdeRuta($rutaRelativa);
        if ($clave === null) {
            return;
        }

        try {
            if ($this->usaS3()) {
                $this->s3->delete(self::S3_PREFIX . $clave);
            } else {
                $ruta = $this->uploadDir . '/' . $clave;
                if (is_file($ruta)) {
                    @unlink($ruta);
                }
            }
            $this->logger->info('SPUI: archivo eliminado.', ['archivo' => $clave]);
        } catch (\Throwable $e) {
            // Que no se pueda borrar el archivo no debe impedir borrar el
            // registro: se registra y se sigue.
            $this->logger->warning('SPUI: no se pudo eliminar el archivo: {msg}', [
                'msg' => $e->getMessage(), 'archivo' => $clave,
            ]);
        }
    }

    /**
     * SHA-256 de un archivo recién subido, calculado ANTES de guardar()
     * mientras todavía es el temporal local de PHP — sea cual sea el backend.
     *
     * Antes esto se resolvía llamando a hash() después de guardar(), que en
     * S3 significaba volver a descargar el archivo entero (URL prefirmada +
     * file_get_contents, sin timeout) sólo para hashearlo: el doble de ancho
     * de banda por cada subida y, si esa descarga se colgaba, la subida
     * entera se quedaba esperando sin avisar nada. Llamar a este método antes
     * de guardar() evita el viaje de vuelta a S3 por completo.
     */
    public function hashArchivoSubido(UploadedFile $archivo): string
    {
        return hash_file('sha256', $archivo->getPathname());
    }

    /**
     * SHA-256 de un archivo YA guardado, a partir de su ruta relativa (sin
     * tener el UploadedFile a mano). En S3 implica descargarlo de vuelta —
     * para un archivo recién subido usar hashArchivoSubido() en su lugar, que
     * lo calcula del temporal local sin ese viaje de más.
     */
    public function hash(string $rutaRelativa): ?string
    {
        $clave = $this->claveDesdeRuta($rutaRelativa);
        if ($clave === null) {
            return null;
        }

        if (!$this->usaS3()) {
            $ruta = $this->uploadDir . '/' . $clave;
            return is_file($ruta) ? hash_file('sha256', $ruta) : null;
        }

        $contenido = $this->leer($rutaRelativa);
        return $contenido === null ? null : hash('sha256', $contenido);
    }

    /**
     * Convierte una ruta relativa guardada en el segmento que
     * /api/spui/media/{filename} espera para servírsela al Pi (o al navegador
     * del CMS, para el preview de sonido de alerta) — 'categoria/nombre', con
     * cada parte codificada por separado para no romper la barra que las
     * separa. Devuelve null si no hay archivo o la ruta es inválida.
     */
    public function rutaParaUrl(?string $rutaRelativa): ?string
    {
        if ($rutaRelativa === null) {
            return null;
        }

        $clave = $this->claveDesdeRuta($rutaRelativa);
        if ($clave === null) {
            return null;
        }

        return implode('/', array_map('rawurlencode', explode('/', $clave)));
    }

    // -------------------------------------------------------------------------

    /** Arma 'categoria/nombre' para un archivo nuevo, validando la categoría. */
    private function claveNueva(string $categoria, string $nombre): string
    {
        if (!in_array($categoria, self::CATEGORIAS, true)) {
            throw new \InvalidArgumentException('Categoría de almacenamiento desconocida: ' . $categoria);
        }

        return $categoria . '/' . $nombre;
    }

    /**
     * Valida y extrae 'categoria/nombre' de una ruta relativa tal como queda
     * guardada en una entidad ('uploads/spui/<categoria>/<nombre>'). Es el
     * único lugar que interpreta ese formato — todo lo demás (S3, disco
     * local, la URL que arma SyncController/AlertaPublisherService) pasa por
     * acá primero, así que agregar una categoría nueva no implica tocar nada
     * más que self::CATEGORIAS.
     *
     * `basename()` sobre el nombre corta cualquier intento de escaparse de su
     * carpeta (p. ej. 'imagenes/../../etc/passwd' → sólo queda 'passwd').
     *
     * @return string|null 'categoria/nombre' saneado, o null si la ruta no
     *                      tiene una categoría reconocida (dato corrupto o de
     *                      un formato viejo — no se intenta adivinar).
     */
    private function claveDesdeRuta(string $rutaRelativa): ?string
    {
        $sinPrefijo = str_starts_with($rutaRelativa, 'uploads/spui/')
            ? substr($rutaRelativa, strlen('uploads/spui/'))
            : $rutaRelativa;

        $partes = explode('/', $sinPrefijo, 2);
        if (count($partes) !== 2 || !in_array($partes[0], self::CATEGORIAS, true) || $partes[1] === '') {
            return null;
        }

        return $partes[0] . '/' . basename($partes[1]);
    }

    /** Directorio local de una categoría, creándolo si hace falta. */
    private function dirLocalDe(string $categoria): string
    {
        if (!in_array($categoria, self::CATEGORIAS, true)) {
            throw new \InvalidArgumentException('Categoría de almacenamiento desconocida: ' . $categoria);
        }

        $dir = $this->uploadDir . '/' . $categoria;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new FileException('No se pudo crear el directorio de subidas: ' . $dir);
        }

        return $dir;
    }

    /**
     * MIME por extensión — al leer de S3 no se conserva el Content-Type
     * original, así que hace falta deducirlo del nombre. Único punto que
     * sabe esto: lo usan MediaController (para la Pi) y los endpoints de
     * preview del CMS (ContenidoCmsController, AlertaCmsController).
     */
    public function tipoMimePorExtension(string $rutaOArchivo): string
    {
        return match (strtolower(pathinfo($rutaOArchivo, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'mp4'         => 'video/mp4',
            'webm'        => 'video/webm',
            'ogv', 'ogg'  => 'video/ogg',
            'mov'         => 'video/quicktime',
            'mp3'         => 'audio/mpeg',
            'wav'         => 'audio/wav',
            default       => 'application/octet-stream',
        };
    }

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
