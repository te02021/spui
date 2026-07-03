<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Pantalla;
use SPUI\Enum\EstadoPantalla;
use SPUI\Repository\PantallaRepository;
use SPUI\Repository\UbicacionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/spui/pantallas')]
class PantallaController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly PantallaRepository $repo,
        private readonly UbicacionRepository $ubicacionRepo,
    ) {}

    private function em(): EntityManagerInterface
    {
        return $this->doctrine->getManager('SPUI');
    }

    private function serialize(Pantalla $p): array
    {
        $u = $p->getUbicacion();
        return [
            'id'               => $p->getId(),
            'nombre'           => $p->getNombre(),
            'ubicacion'        => [
                'id'       => $u->getId(),
                'edificio' => $u->getEdificio()->getNombre(),
                'sector'   => $u->getSector(),
            ],
            'ip_address'       => $p->getIpAddress(),
            'mac_address'      => $p->getMacAddress(),
            'resolucion_ancho' => $p->getResolucionAncho(),
            'resolucion_alto'  => $p->getResolucionAlto(),
            'estado'           => $p->getEstado()->value,
            'reproductor_id'   => $p->getReproductor()?->getId(),
            'creado_en'        => $p->getCreadoEn()->format('c'),
            'actualizado_en'   => $p->getActualizadoEn()->format('c'),
        ];
    }

    /** Valida formato MAC: AA:BB:CC:DD:EE:FF */
    private function isValidMac(string $mac): bool
    {
        return (bool) preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac);
    }

    #[Route('', name: 'spui_pantallas_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $criteria = [];
        if ($request->query->has('estado')) {
            $estado = EstadoPantalla::tryFrom($request->query->getString('estado'));
            if ($estado === null) {
                return $this->json(['error' => 'Estado inválido. Valores posibles: activo, inactivo, mantenimiento.'], Response::HTTP_BAD_REQUEST);
            }
            $criteria['estado'] = $estado;
        }
        if ($request->query->has('ubicacion_id')) {
            $criteria['ubicacion'] = $request->query->getInt('ubicacion_id');
        }

        $items = $this->repo->findBy($criteria, ['nombre' => 'ASC']);

        return $this->json([
            'data'  => array_map($this->serialize(...), $items),
            'total' => count($items),
        ]);
    }

    #[Route('', name: 'spui_pantallas_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        $errors = [];
        if (empty($body['nombre'])) {
            $errors[] = '"nombre" es requerido.';
        }
        if (empty($body['ubicacion_id'])) {
            $errors[] = '"ubicacion_id" es requerido.';
        }
        if (empty($body['mac_address'])) {
            $errors[] = '"mac_address" es requerido.';
        } elseif (!$this->isValidMac($body['mac_address'])) {
            $errors[] = '"mac_address" debe tener formato AA:BB:CC:DD:EE:FF.';
        }
        if (!empty($errors)) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ubicacion = $this->ubicacionRepo->find($body['ubicacion_id']);
        if ($ubicacion === null) {
            return $this->json(['error' => 'Ubicación no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        // Verificar mac_address única
        $existing = $this->repo->findOneBy(['macAddress' => strtoupper($body['mac_address'])]);
        if ($existing !== null) {
            return $this->json(['error' => 'Ya existe una pantalla con esa MAC address.'], Response::HTTP_CONFLICT);
        }

        $estado = EstadoPantalla::tryFrom($body['estado'] ?? 'activo') ?? EstadoPantalla::Activo;

        $pantalla = new Pantalla();
        $pantalla->setNombre(trim($body['nombre']));
        $pantalla->setUbicacion($ubicacion);
        $pantalla->setMacAddress(strtoupper($body['mac_address']));
        $pantalla->setIpAddress(isset($body['ip_address']) ? trim($body['ip_address']) : null);
        $pantalla->setResolucionAncho((int) ($body['resolucion_ancho'] ?? 1920));
        $pantalla->setResolucionAlto((int) ($body['resolucion_alto'] ?? 1080));
        $pantalla->setEstado($estado);

        $em = $this->em();
        $em->persist($pantalla);
        $em->flush();

        return $this->json(['data' => $this->serialize($pantalla)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spui_pantallas_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $pantalla = $this->repo->find($id);
        if ($pantalla === null) {
            return $this->json(['error' => 'Pantalla no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->serialize($pantalla)]);
    }

    #[Route('/{id}', name: 'spui_pantallas_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $pantalla = $this->repo->find($id);
        if ($pantalla === null) {
            return $this->json(['error' => 'Pantalla no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (isset($body['nombre'])) {
            $pantalla->setNombre(trim($body['nombre']));
        }
        if (isset($body['ubicacion_id'])) {
            $ubicacion = $this->ubicacionRepo->find($body['ubicacion_id']);
            if ($ubicacion === null) {
                return $this->json(['error' => 'Ubicación no encontrada.'], Response::HTTP_NOT_FOUND);
            }
            $pantalla->setUbicacion($ubicacion);
        }
        if (array_key_exists('ip_address', $body)) {
            $pantalla->setIpAddress($body['ip_address'] !== null ? trim($body['ip_address']) : null);
        }
        if (isset($body['mac_address'])) {
            if (!$this->isValidMac($body['mac_address'])) {
                return $this->json(['error' => '"mac_address" debe tener formato AA:BB:CC:DD:EE:FF.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $mac = strtoupper($body['mac_address']);
            $existing = $this->repo->findOneBy(['macAddress' => $mac]);
            if ($existing !== null && $existing->getId() !== $pantalla->getId()) {
                return $this->json(['error' => 'Ya existe una pantalla con esa MAC address.'], Response::HTTP_CONFLICT);
            }
            $pantalla->setMacAddress($mac);
        }
        if (isset($body['resolucion_ancho'])) {
            $pantalla->setResolucionAncho((int) $body['resolucion_ancho']);
        }
        if (isset($body['resolucion_alto'])) {
            $pantalla->setResolucionAlto((int) $body['resolucion_alto']);
        }
        if (isset($body['estado'])) {
            $estado = EstadoPantalla::tryFrom($body['estado']);
            if ($estado === null) {
                return $this->json(['error' => 'Estado inválido. Valores posibles: activo, inactivo, mantenimiento.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $pantalla->setEstado($estado);
        }

        $this->em()->flush();

        return $this->json(['data' => $this->serialize($pantalla)]);
    }

    #[Route('/{id}', name: 'spui_pantallas_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $pantalla = $this->repo->find($id);
        if ($pantalla === null) {
            return $this->json(['error' => 'Pantalla no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if ($pantalla->getReproductor() !== null) {
            return $this->json(
                ['error' => 'No se puede eliminar una pantalla con reproductor asociado. Desasignalo primero.'],
                Response::HTTP_CONFLICT,
            );
        }

        $em = $this->em();
        $em->remove($pantalla);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
