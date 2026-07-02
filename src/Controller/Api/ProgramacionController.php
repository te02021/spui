<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Programacion;
use SPUI\Repository\PantallaRepository;
use SPUI\Repository\PlaylistRepository;
use SPUI\Repository\ProgramacionRepository;
use SPUI\Repository\UbicacionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/spui/programaciones')]
class ProgramacionController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ProgramacionRepository $repo,
        private readonly PantallaRepository $pantallaRepo,
        private readonly UbicacionRepository $ubicacionRepo,
        private readonly PlaylistRepository $playlistRepo,
        private readonly ValidatorInterface $validator,
    ) {}

    private function em(): EntityManagerInterface
    {
        return $this->doctrine->getManager('SPUI');
    }

    private function serialize(Programacion $p): array
    {
        return [
            'id'             => $p->getId(),
            'pantalla'       => $p->getPantalla() ? ['id' => $p->getPantalla()->getId(), 'nombre' => $p->getPantalla()->getNombre()] : null,
            'ubicacion'      => $p->getUbicacion() ? ['id' => $p->getUbicacion()->getId(), 'edificio' => $p->getUbicacion()->getEdificio()] : null,
            'playlist'       => ['id' => $p->getPlaylist()->getId(), 'nombre' => $p->getPlaylist()->getNombre()],
            'fecha_inicio'   => $p->getFechaInicio()->format('Y-m-d'),
            'fecha_fin'      => $p->getFechaFin()?->format('Y-m-d'),
            'hora_inicio'    => $p->getHoraInicio()->format('H:i'),
            'hora_fin'       => $p->getHoraFin()->format('H:i'),
            'dias_semana'    => $p->getDiasSemana(),
            'dias_nombre'    => $this->diasBitmaskToNombres($p->getDiasSemana()),
            'prioridad'      => $p->getPrioridad(),
            'activo'         => $p->isActivo(),
            'creado_por_id'  => $p->getCreadoPorId(),
            'creado_en'      => $p->getCreadoEn()->format('c'),
        ];
    }

    /**
     * Convierte el bitmask a array de nombres: 31 → ['lun','mar','mie','jue','vie']
     * bit0=lun, bit1=mar, ..., bit6=dom
     */
    private function diasBitmaskToNombres(int $mask): array
    {
        $nombres = ['lun', 'mar', 'mie', 'jue', 'vie', 'sab', 'dom'];
        return array_values(array_filter($nombres, fn($_, int $i) => (bool) ($mask & (1 << $i)), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Convierte array de nombres a bitmask: ['lun','vie'] → 17
     */
    private function diasNombresToBitmask(array $nombres): int
    {
        $map  = ['lun' => 0, 'mar' => 1, 'mie' => 2, 'jue' => 3, 'vie' => 4, 'sab' => 5, 'dom' => 6];
        $mask = 0;
        foreach ($nombres as $nombre) {
            if (isset($map[strtolower($nombre)])) {
                $mask |= (1 << $map[strtolower($nombre)]);
            }
        }
        return $mask;
    }

    #[Route('', name: 'spui_programaciones_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $criteria = [];
        if ($request->query->has('pantalla_id'))  $criteria['pantalla']  = $request->query->getInt('pantalla_id');
        if ($request->query->has('ubicacion_id')) $criteria['ubicacion'] = $request->query->getInt('ubicacion_id');
        if ($request->query->has('activo'))       $criteria['activo']    = $request->query->getBoolean('activo');

        $items = $this->repo->findBy($criteria, ['prioridad' => 'DESC', 'horaInicio' => 'ASC']);

        return $this->json([
            'data'  => array_map($this->serialize(...), $items),
            'total' => count($items),
        ]);
    }

    #[Route('', name: 'spui_programaciones_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        $violations = $this->validator->validate($body, new Assert\Collection([
            'allowExtraFields'   => true,
            'allowMissingFields' => false,
            'fields' => [
                'playlist_id'   => [new Assert\NotBlank(), new Assert\Positive()],
                'fecha_inicio'  => [new Assert\NotBlank(), new Assert\Date()],
                'hora_inicio'   => [new Assert\NotBlank(), new Assert\Regex(['pattern' => '/^\d{2}:\d{2}$/', 'message' => 'Formato esperado: HH:MM'])],
                'hora_fin'      => [new Assert\NotBlank(), new Assert\Regex(['pattern' => '/^\d{2}:\d{2}$/', 'message' => 'Formato esperado: HH:MM'])],
                'creado_por_id' => [new Assert\NotBlank(), new Assert\Positive()],
            ],
        ]));

        if (empty($body['pantalla_id']) && empty($body['ubicacion_id'])) {
            return $this->json(['errors' => ['Se requiere "pantalla_id" o "ubicacion_id" (no ambos nulos).']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[] = $v->getPropertyPath() . ': ' . $v->getMessage();
            }
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $playlist = $this->playlistRepo->find($body['playlist_id']);
        if ($playlist === null) {
            return $this->json(['error' => 'Playlist no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $pantalla  = null;
        $ubicacion = null;
        if (!empty($body['pantalla_id'])) {
            $pantalla = $this->pantallaRepo->find($body['pantalla_id']);
            if ($pantalla === null) {
                return $this->json(['error' => 'Pantalla no encontrada.'], Response::HTTP_NOT_FOUND);
            }
        }
        if (!empty($body['ubicacion_id'])) {
            $ubicacion = $this->ubicacionRepo->find($body['ubicacion_id']);
            if ($ubicacion === null) {
                return $this->json(['error' => 'Ubicación no encontrada.'], Response::HTTP_NOT_FOUND);
            }
        }

        // Parsear días de semana: acepta entero (bitmask) o array de nombres
        $diasSemana = 31; // lun-vie por defecto
        if (isset($body['dias_semana'])) {
            $diasSemana = is_array($body['dias_semana'])
                ? $this->diasNombresToBitmask($body['dias_semana'])
                : (int) $body['dias_semana'];
        }

        $prog = new Programacion();
        $prog->setPlaylist($playlist);
        $prog->setPantalla($pantalla);
        $prog->setUbicacion($ubicacion);
        $prog->setFechaInicio(new DateTimeImmutable($body['fecha_inicio']));
        $prog->setFechaFin(!empty($body['fecha_fin']) ? new DateTimeImmutable($body['fecha_fin']) : null);
        $prog->setHoraInicio(new DateTimeImmutable($body['hora_inicio']));
        $prog->setHoraFin(new DateTimeImmutable($body['hora_fin']));
        $prog->setDiasSemana($diasSemana);
        $prog->setPrioridad((int) ($body['prioridad'] ?? 5));
        $prog->setActivo((bool) ($body['activo'] ?? true));
        $prog->setCreadoPorId((int) $body['creado_por_id']);

        $em = $this->em();
        $em->persist($prog);
        $em->flush();

        return $this->json(['data' => $this->serialize($prog)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spui_programaciones_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $prog = $this->repo->find($id);
        if ($prog === null) {
            return $this->json(['error' => 'Programación no encontrada.'], Response::HTTP_NOT_FOUND);
        }
        return $this->json(['data' => $this->serialize($prog)]);
    }

    #[Route('/{id}', name: 'spui_programaciones_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $prog = $this->repo->find($id);
        if ($prog === null) {
            return $this->json(['error' => 'Programación no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (isset($body['playlist_id'])) {
            $playlist = $this->playlistRepo->find($body['playlist_id']);
            if ($playlist === null) {
                return $this->json(['error' => 'Playlist no encontrada.'], Response::HTTP_NOT_FOUND);
            }
            $prog->setPlaylist($playlist);
        }
        if (isset($body['pantalla_id'])) {
            $pantalla = $body['pantalla_id'] ? $this->pantallaRepo->find($body['pantalla_id']) : null;
            $prog->setPantalla($pantalla);
        }
        if (isset($body['ubicacion_id'])) {
            $ubicacion = $body['ubicacion_id'] ? $this->ubicacionRepo->find($body['ubicacion_id']) : null;
            $prog->setUbicacion($ubicacion);
        }
        if (isset($body['fecha_inicio'])) {
            $prog->setFechaInicio(new DateTimeImmutable($body['fecha_inicio']));
        }
        if (array_key_exists('fecha_fin', $body)) {
            $prog->setFechaFin($body['fecha_fin'] ? new DateTimeImmutable($body['fecha_fin']) : null);
        }
        if (isset($body['hora_inicio'])) {
            $prog->setHoraInicio(new DateTimeImmutable($body['hora_inicio']));
        }
        if (isset($body['hora_fin'])) {
            $prog->setHoraFin(new DateTimeImmutable($body['hora_fin']));
        }
        if (isset($body['dias_semana'])) {
            $prog->setDiasSemana(
                is_array($body['dias_semana'])
                    ? $this->diasNombresToBitmask($body['dias_semana'])
                    : (int) $body['dias_semana']
            );
        }
        if (isset($body['prioridad'])) {
            $prog->setPrioridad((int) $body['prioridad']);
        }
        if (isset($body['activo'])) {
            $prog->setActivo((bool) $body['activo']);
        }

        $this->em()->flush();

        return $this->json(['data' => $this->serialize($prog)]);
    }

    #[Route('/{id}', name: 'spui_programaciones_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $prog = $this->repo->find($id);
        if ($prog === null) {
            return $this->json(['error' => 'Programación no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $em = $this->em();
        $em->remove($prog);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
