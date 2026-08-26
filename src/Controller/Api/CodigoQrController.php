<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Repository\CodigoQrRepository;
use SPUI\Service\QrGeneratorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sólo la imagen del QR y su redirect — son las dos rutas públicas que de
 * verdad escanea un celular. El CRUD (index/create/show/update/delete) que
 * tenía este controller antes era un duplicado JSON de CodigoQrCmsController,
 * sin ningún consumidor real (nada en el CMS ni en el pi-client lo llamaba):
 * se dio de baja junto con el resto de la capa Api/*Controller huérfana
 * (Alerta/Contenido/Pantalla/Playlist/Programacion/Reproductor/Ubicacion).
 */
#[Route('/api/spui/qr')]
class CodigoQrController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly CodigoQrRepository $repo,
        private readonly QrGeneratorService $qrGenerator,
    ) {}

    private function em(): EntityManagerInterface
    {
        return $this->doctrine->getManager('SPUI');
    }

    /**
     * Devuelve la imagen PNG del QR.
     * El QR codifica la URL de redirect del CMS, no la URL destino directamente
     * (así el contador funciona aunque cambie la URL destino).
     */
    #[Route('/{id}/imagen', name: 'spui_qr_imagen', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function imagen(int $id, Request $request): Response
    {
        $qr = $this->repo->find($id);
        if ($qr === null) {
            return $this->json(['error' => 'Código QR no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        // Lo que el QR codifica: URL del endpoint /r, no la URL destino
        $redirectUrl = $request->getSchemeAndHttpHost() . '/api/spui/qr/' . $id . '/r';
        $pngBytes    = $this->qrGenerator->generarPng($redirectUrl);

        return new Response(
            $pngBytes,
            Response::HTTP_OK,
            [
                'Content-Type'        => $this->qrGenerator->mimeType(),
                'Content-Disposition' => 'inline; filename="qr-' . $id . '.png"',
                'Cache-Control'       => 'public, max-age=3600',
            ],
        );
    }

    /**
     * Endpoint de redirect: scaneado el QR, el dispositivo llega aquí.
     * Incrementa el contador y redirige a url_destino.
     * Es público (sin auth): cualquier celular puede escanear el QR.
     */
    #[Route('/{id}/r', name: 'spui_qr_redirect', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function redirigir(int $id): Response
    {
        $qr = $this->repo->find($id);

        if ($qr === null || !$qr->estaVigente()) {
            return new Response(
                '<html><body><h2>Este código QR no está disponible.</h2></body></html>',
                Response::HTTP_GONE,
                ['Content-Type' => 'text/html'],
            );
        }

        $qr->incrementarUsos();
        $this->em()->flush();

        return new RedirectResponse($qr->getUrlDestino(), Response::HTTP_FOUND);
    }
}
