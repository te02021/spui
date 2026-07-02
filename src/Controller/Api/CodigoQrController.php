<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\CodigoQr;
use SPUI\Repository\CodigoQrRepository;
use SPUI\Service\QrGeneratorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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

    private function serialize(CodigoQr $qr, ?string $baseUrl = null): array
    {
        return [
            'id'           => $qr->getId(),
            'etiqueta'     => $qr->getEtiqueta(),
            'url_destino'  => $qr->getUrlDestino(),
            'activo'       => $qr->isActivo(),
            'esta_vigente' => $qr->estaVigente(),
            'usos_count'   => $qr->getUsosCount(),
            'expira_en'    => $qr->getExpiraEn()?->format('c'),
            'creado_en'    => $qr->getCreadoEn()->format('c'),
            'url_redirect' => $baseUrl ? $baseUrl . '/api/spui/qr/' . $qr->getId() . '/r' : null,
            'url_imagen'   => $baseUrl ? $baseUrl . '/api/spui/qr/' . $qr->getId() . '/imagen' : null,
        ];
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    #[Route('', name: 'spui_qr_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $criteria = [];
        if ($request->query->has('activo')) {
            $criteria['activo'] = $request->query->getBoolean('activo');
        }

        $items = $this->repo->findBy($criteria, ['creadoEn' => 'DESC']);
        $base  = $request->getSchemeAndHttpHost();

        return $this->json([
            'data'  => array_map(fn(CodigoQr $qr) => $this->serialize($qr, $base), $items),
            'total' => count($items),
        ]);
    }

    #[Route('', name: 'spui_qr_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        $errors = [];
        if (empty($body['etiqueta']))    $errors[] = '"etiqueta" es requerido.';
        if (empty($body['url_destino'])) $errors[] = '"url_destino" es requerido.';
        if (!empty($errors)) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!filter_var($body['url_destino'], FILTER_VALIDATE_URL)) {
            return $this->json(['error' => '"url_destino" debe ser una URL válida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $qr = new CodigoQr();
        $qr->setEtiqueta(trim($body['etiqueta']));
        $qr->setUrlDestino(trim($body['url_destino']));
        $qr->setActivo((bool) ($body['activo'] ?? true));

        if (!empty($body['expira_en'])) {
            $qr->setExpiraEn(new DateTimeImmutable($body['expira_en']));
        }

        $em = $this->em();
        $em->persist($qr);
        $em->flush();

        return $this->json(
            ['data' => $this->serialize($qr, $request->getSchemeAndHttpHost())],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'spui_qr_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): JsonResponse
    {
        $qr = $this->repo->find($id);
        if ($qr === null) {
            return $this->json(['error' => 'Código QR no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->serialize($qr, $request->getSchemeAndHttpHost())]);
    }

    #[Route('/{id}', name: 'spui_qr_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $qr = $this->repo->find($id);
        if ($qr === null) {
            return $this->json(['error' => 'Código QR no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (isset($body['etiqueta'])) {
            $qr->setEtiqueta(trim($body['etiqueta']));
        }
        if (isset($body['url_destino'])) {
            if (!filter_var($body['url_destino'], FILTER_VALIDATE_URL)) {
                return $this->json(['error' => '"url_destino" debe ser una URL válida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $qr->setUrlDestino(trim($body['url_destino']));
        }
        if (isset($body['activo'])) {
            $qr->setActivo((bool) $body['activo']);
        }
        if (array_key_exists('expira_en', $body)) {
            $qr->setExpiraEn($body['expira_en'] ? new DateTimeImmutable($body['expira_en']) : null);
        }

        $this->em()->flush();

        return $this->json(['data' => $this->serialize($qr, $request->getSchemeAndHttpHost())]);
    }

    #[Route('/{id}', name: 'spui_qr_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $qr = $this->repo->find($id);
        if ($qr === null) {
            return $this->json(['error' => 'Código QR no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $em = $this->em();
        $em->remove($qr);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Imagen y redirect
    // -------------------------------------------------------------------------

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
