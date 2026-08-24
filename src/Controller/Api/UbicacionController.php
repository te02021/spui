<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Ubicacion;
use SPUI\Repository\EdificioRepository;
use SPUI\Repository\UbicacionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/spui/ubicaciones')]
class UbicacionController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly UbicacionRepository $repo,
        private readonly EdificioRepository $edificioRepo,
    ) {}

    private function em(): EntityManagerInterface
    {
        return $this->doctrine->getManager('SPUI');
    }

    private function serialize(Ubicacion $u): array
    {
        $e = $u->getEdificio();
        return [
            'id'              => $u->getId(),
            'edificio'        => [
                'id'     => $e->getId(),
                'nombre' => $e->getNombre(),
            ],
            'sector'          => $u->getSector(),
            'descripcion'     => $u->getDescripcion(),
            'activo'          => $u->isActivo(),
            'pantallas_count' => $u->getPantallas()->count(),
        ];
    }

    #[Route('', name: 'spui_ubicaciones_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $qb = $this->repo->createQueryBuilder('u')
            ->join('u.edificio', 'e')
            ->addSelect('e')
            ->orderBy('e.nombre', 'ASC')
            ->addOrderBy('u.sector', 'ASC');

        if ($request->query->has('activo')) {
            $qb->andWhere('u.activo = :activo')
               ->setParameter('activo', $request->query->getBoolean('activo'));
        }

        $items = $qb->getQuery()->getResult();

        return $this->json([
            'data'  => array_map($this->serialize(...), $items),
            'total' => count($items),
        ]);
    }

    #[Route('', name: 'spui_ubicaciones_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        if (empty($body['edificio_id'])) {
            return $this->json(['error' => 'El campo "edificio_id" es requerido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $edificio = $this->edificioRepo->find((int) $body['edificio_id']);
        if ($edificio === null) {
            return $this->json(['error' => 'Edificio no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $ubicacion = new Ubicacion();
        $ubicacion->setEdificio($edificio);
        $ubicacion->setSector(isset($body['sector']) ? trim($body['sector']) : null);
        $ubicacion->setDescripcion(isset($body['descripcion']) ? trim($body['descripcion']) : null);
        $ubicacion->setActivo((bool) ($body['activo'] ?? true));

        $em = $this->em();
        $em->persist($ubicacion);
        $em->flush();

        return $this->json(['data' => $this->serialize($ubicacion)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spui_ubicaciones_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $ubicacion = $this->repo->find($id);
        if ($ubicacion === null) {
            return $this->json(['error' => 'Ubicación no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->serialize($ubicacion)]);
    }

    #[Route('/{id}', name: 'spui_ubicaciones_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $ubicacion = $this->repo->find($id);
        if ($ubicacion === null) {
            return $this->json(['error' => 'Ubicación no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (isset($body['edificio_id'])) {
            $edificio = $this->edificioRepo->find((int) $body['edificio_id']);
            if ($edificio === null) {
                return $this->json(['error' => 'Edificio no encontrado.'], Response::HTTP_NOT_FOUND);
            }
            $ubicacion->setEdificio($edificio);
        }
        if (array_key_exists('sector', $body)) {
            $ubicacion->setSector($body['sector'] !== null ? trim($body['sector']) : null);
        }
        if (array_key_exists('descripcion', $body)) {
            $ubicacion->setDescripcion($body['descripcion'] !== null ? trim($body['descripcion']) : null);
        }
        if (isset($body['activo'])) {
            $ubicacion->setActivo((bool) $body['activo']);
        }

        $this->em()->flush();

        return $this->json(['data' => $this->serialize($ubicacion)]);
    }

    #[Route('/{id}', name: 'spui_ubicaciones_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $ubicacion = $this->repo->find($id);
        if ($ubicacion === null) {
            return $this->json(['error' => 'Ubicación no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$ubicacion->getPantallas()->isEmpty()) {
            return $this->json(
                ['error' => 'No se puede eliminar una ubicación con pantallas asignadas. Desasígnalas primero.'],
                Response::HTTP_CONFLICT,
            );
        }

        // Sin este guard el DELETE viola la FK de programacion.ubicacion_id
        if (!$ubicacion->getProgramaciones()->isEmpty()) {
            return $this->json(
                ['error' => 'No se puede eliminar una ubicación con programaciones asociadas. Eliminalas primero.'],
                Response::HTTP_CONFLICT,
            );
        }

        $em = $this->em();
        $em->remove($ubicacion);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
