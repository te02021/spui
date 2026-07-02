<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Reproductor;
use SPUI\Enum\EstadoConexion;
use SPUI\Repository\ReproductorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/spui/reproductores')]
class ReproductorController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ReproductorRepository $repo,
    ) {}

    private function em(): EntityManagerInterface
    {
        return $this->doctrine->getManager('SPUI');
    }

    private function serialize(Reproductor $r, ?string $rawKey = null): array
    {
        $data = [
            'id'               => $r->getId(),
            'hostname'         => $r->getHostname(),
            'version_firmware' => $r->getVersionFirmware(),
            'estado_conexion'  => $r->getEstadoConexion()->value,
            'ultimo_heartbeat' => $r->getUltimoHeartbeat()?->format('c'),
            'pantallas'        => array_map(
                fn($p) => ['id' => $p->getId(), 'nombre' => $p->getNombre()],
                $r->getPantallas()->toArray(),
            ),
        ];
        if ($rawKey !== null) {
            $data['api_key'] = $rawKey;
            $data['_aviso']  = 'Guardá esta clave. No se mostrará de nuevo.';
        }
        return $data;
    }

    private function generateApiKey(): array
    {
        $rawKey = bin2hex(random_bytes(32));
        $hash   = hash('sha256', $rawKey);
        return [$rawKey, $hash];
    }

    #[Route('', name: 'spui_reproductores_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $items = $this->repo->findBy([], ['id' => 'ASC']);
        return $this->json([
            'data'  => array_map(fn(Reproductor $r) => $this->serialize($r), $items),
            'total' => count($items),
        ]);
    }

    #[Route('', name: 'spui_reproductores_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        if (empty($body['hostname'])) {
            return $this->json(['error' => '"hostname" es requerido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        [$rawKey, $hash] = $this->generateApiKey();

        $reproductor = new Reproductor();
        $reproductor->setHostname(trim($body['hostname']));
        $reproductor->setVersionFirmware(isset($body['version_firmware']) ? trim($body['version_firmware']) : null);
        $reproductor->setApiKeyHash($hash);
        $reproductor->setEstadoConexion(EstadoConexion::SinRegistrar);

        $em = $this->em();
        $em->persist($reproductor);
        $em->flush();

        return $this->json(['data' => $this->serialize($reproductor, $rawKey)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spui_reproductores_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $reproductor = $this->repo->find($id);
        if ($reproductor === null) {
            return $this->json(['error' => 'Reproductor no encontrado.'], Response::HTTP_NOT_FOUND);
        }
        return $this->json(['data' => $this->serialize($reproductor)]);
    }

    #[Route('/{id}', name: 'spui_reproductores_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $reproductor = $this->repo->find($id);
        if ($reproductor === null) {
            return $this->json(['error' => 'Reproductor no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (isset($body['hostname'])) {
            $reproductor->setHostname(trim($body['hostname']));
        }
        if (array_key_exists('version_firmware', $body)) {
            $reproductor->setVersionFirmware($body['version_firmware'] !== null ? trim($body['version_firmware']) : null);
        }
        if (isset($body['estado_conexion'])) {
            $estado = EstadoConexion::tryFrom($body['estado_conexion']);
            if ($estado === null) {
                return $this->json(['error' => 'estado_conexion inválido. Valores: conectado, desconectado, sin_registrar.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $reproductor->setEstadoConexion($estado);
        }

        $this->em()->flush();

        return $this->json(['data' => $this->serialize($reproductor)]);
    }

    #[Route('/{id}/regenerar-clave', name: 'spui_reproductores_regenerar_clave', methods: ['POST'])]
    public function regenerarClave(int $id): JsonResponse
    {
        $reproductor = $this->repo->find($id);
        if ($reproductor === null) {
            return $this->json(['error' => 'Reproductor no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        [$rawKey, $hash] = $this->generateApiKey();
        $reproductor->setApiKeyHash($hash);
        $this->em()->flush();

        return $this->json(['data' => $this->serialize($reproductor, $rawKey)]);
    }

    #[Route('/{id}', name: 'spui_reproductores_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $reproductor = $this->repo->find($id);
        if ($reproductor === null) {
            return $this->json(['error' => 'Reproductor no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $em = $this->em();
        $em->remove($reproductor);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
