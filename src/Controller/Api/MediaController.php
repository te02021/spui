<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use SPUI\Service\MediaStorageService;
use SPUI\Service\ReproductorAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sirve los archivos de contenido (imágenes, videos) a los reproductores Pi.
 *
 * El Pi siempre pide por acá, sin importar dónde estén guardados: con el
 * backend local se sirve del disco y con S3 se hace de intermediario. Así el
 * nombre de archivo que ve el Pi es estable (su caché local se llama igual) y
 * no hay que exponerle credenciales ni URLs firmadas que vencen.
 *
 * El hash SHA-256 que devuelve el sync coincide con el del archivo, y el Pi
 * lo verifica después de descargar.
 */
#[Route('/api/spui/media')]
class MediaController extends AbstractController
{
    public function __construct(
        private readonly MediaStorageService $media,
        private readonly ReproductorAuthService $authService,
    ) {}

    /**
     * Descarga un archivo de contenido.
     *
     * Pide X-Api-Key igual que /sync y /heartbeat: antes cualquiera que
     * adivinara un nombre de archivo podía bajarlo sin credencial. El Pi ya
     * mandaba el header (reusa la misma sesión HTTP para todo), así que no
     * hubo que tocar el cliente.
     */
    #[Route('/{filename}', name: 'spui_media_serve', methods: ['GET'], requirements: ['filename' => '[^/]+'])]
    public function serve(string $filename, Request $request): Response
    {
        if ($this->authService->autenticar($request) === null) {
            return $this->json(['error' => 'Clave de API inválida o ausente.'], Response::HTTP_UNAUTHORIZED);
        }

        // Prevenir path traversal: sólo nombre de archivo, sin directorios.
        $safeFilename = basename($filename);

        // Con backend local se responde con BinaryFileResponse, que delega el
        // envío al servidor web y no carga el archivo entero en memoria (los
        // videos institucionales pueden pesar bastante).
        $rutaLocal = $this->media->rutaLocal($safeFilename);
        if ($rutaLocal !== null) {
            $response = new BinaryFileResponse($rutaLocal);
            $response->setAutoLastModified();
            $response->setAutoEtag();
            return $response;
        }

        $contenido = $this->media->leer($safeFilename);
        if ($contenido === null) {
            throw new NotFoundHttpException('Archivo no encontrado.');
        }

        $response = new Response($contenido, Response::HTTP_OK);
        $response->headers->set('Content-Type', $this->tipoMime($safeFilename));
        $response->setEtag(hash('sha256', $contenido));
        $response->setPublic();

        return $response;
    }

    /** MIME por extensión: al leer de S3 no se conserva el Content-Type original. */
    private function tipoMime(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'mp4'         => 'video/mp4',
            'webm'        => 'video/webm',
            'ogv', 'ogg'  => 'video/ogg',
            'mov'         => 'video/quicktime',
            default       => 'application/octet-stream',
        };
    }
}
