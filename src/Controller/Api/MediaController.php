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
     *
     * {filename} lleva la categoría adelante ('imagenes/foo.jpg', no sólo
     * 'foo.jpg') desde que MediaStorageService separó spui/media/ en
     * subcarpetas — por eso el requirement acepta una barra. La validación de
     * que la categoría sea una de las conocidas (y que el nombre no se
     * escape con '../') la hace MediaStorageService, no acá.
     */
    #[Route('/{filename}', name: 'spui_media_serve', methods: ['GET'], requirements: ['filename' => '.+'])]
    public function serve(string $filename, Request $request): Response
    {
        if ($this->authService->autenticar($request) === null) {
            return $this->json(['error' => 'Clave de API inválida o ausente.'], Response::HTTP_UNAUTHORIZED);
        }

        // Con backend local se responde con BinaryFileResponse, que delega el
        // envío al servidor web y no carga el archivo entero en memoria (los
        // videos institucionales pueden pesar bastante).
        $rutaLocal = $this->media->rutaLocal($filename);
        if ($rutaLocal !== null) {
            $response = new BinaryFileResponse($rutaLocal);
            $response->setAutoLastModified();
            $response->setAutoEtag();
            return $response;
        }

        $contenido = $this->media->leer($filename);
        if ($contenido === null) {
            throw new NotFoundHttpException('Archivo no encontrado.');
        }

        $response = new Response($contenido, Response::HTTP_OK);
        $response->headers->set('Content-Type', $this->media->tipoMimePorExtension($filename));
        $response->setEtag(hash('sha256', $contenido));
        $response->setPublic();

        return $response;
    }
}
