<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sirve archivos de contenido (imágenes, videos) para descarga por los nodos Pi.
 * Los archivos se almacenan en public/uploads/spui/.
 */
#[Route('/api/spui/media')]
class MediaController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/spui')]
        private readonly string $uploadDir,
    ) {}

    /**
     * Descarga un archivo de contenido.
     * El Pi lo usa para cachear localmente antes de reproducirlo.
     * El hash SHA-256 del archivo coincide con contenido.hash_archivo para verificar integridad.
     */
    #[Route('/{filename}', name: 'spui_media_serve', methods: ['GET'], requirements: ['filename' => '[^/]+'])]
    public function serve(string $filename): BinaryFileResponse
    {
        // Prevenir path traversal: solo nombre de archivo, sin directorios
        $safeFilename = basename($filename);

        $filePath = $this->uploadDir . '/' . $safeFilename;

        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new NotFoundHttpException('Archivo no encontrado.');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setAutoLastModified();
        $response->setAutoEtag();

        return $response;
    }
}
