<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\AlertaEmergencia;
use SPUI\Repository\AlertaEmergenciaRepository;
use SPUI\Repository\ContenidoRepository;
use SPUI\Service\AlertaPublisherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/spui/alertas')]
class AlertaEmergenciaController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly AlertaEmergenciaRepository $repo,
        private readonly ContenidoRepository $contenidoRepo,
        private readonly AlertaPublisherService $publisher,
    ) {}

    private function em(): EntityManagerInterface
    {
        return $this->doctrine->getManager('SPUI');
    }

    private function serialize(AlertaEmergencia $a): array
    {
        return [
            'id'           => $a->getId(),
            'titulo'       => $a->getTitulo(),
            'mensaje'      => $a->getMensaje(),
            'activa'       => $a->isActiva(),
            'prioridad'    => $a->getPrioridad(),
            'contenido_id' => $a->getContenido()?->getId(),
            'creado_por_id' => $a->getCreadoPorId(),
            'creada_en'    => $a->getCreadaEn()->format('c'),
            'activada_en'  => $a->getActivadaEn()?->format('c'),
            'expira_en'    => $a->getExpiraEn()?->format('c'),
            'ha_expirado'  => $a->haExpirado(),
        ];
    }

    #[Route('', name: 'spui_alertas_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $criteria = [];
        if ($request->query->has('activa')) {
            $criteria['activa'] = $request->query->getBoolean('activa');
        }

        $items = $this->repo->findBy($criteria, ['prioridad' => 'DESC', 'creadaEn' => 'DESC']);

        return $this->json([
            'data'  => array_map($this->serialize(...), $items),
            'total' => count($items),
        ]);
    }

    #[Route('', name: 'spui_alertas_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        $errors = [];
        if (empty($body['titulo']))        $errors[] = '"titulo" es requerido.';
        if (empty($body['mensaje']))       $errors[] = '"mensaje" es requerido.';
        if (empty($body['creado_por_id'])) $errors[] = '"creado_por_id" es requerido.';
        if (!empty($errors)) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $alerta = new AlertaEmergencia();
        $alerta->setTitulo(trim($body['titulo']));
        $alerta->setMensaje(trim($body['mensaje']));
        $alerta->setPrioridad((int) ($body['prioridad'] ?? 10));
        $alerta->setCreadoPorId((int) $body['creado_por_id']);

        if (!empty($body['expira_en'])) {
            $alerta->setExpiraEn(new DateTimeImmutable($body['expira_en']));
        }
        if (!empty($body['contenido_id'])) {
            $contenido = $this->contenidoRepo->find($body['contenido_id']);
            if ($contenido === null) {
                return $this->json(['error' => 'Contenido no encontrado.'], Response::HTTP_NOT_FOUND);
            }
            $alerta->setContenido($contenido);
        }

        $em = $this->em();
        $em->persist($alerta);
        $em->flush();

        return $this->json(['data' => $this->serialize($alerta)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spui_alertas_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $alerta = $this->repo->find($id);
        if ($alerta === null) {
            return $this->json(['error' => 'Alerta no encontrada.'], Response::HTTP_NOT_FOUND);
        }
        return $this->json(['data' => $this->serialize($alerta)]);
    }

    #[Route('/{id}', name: 'spui_alertas_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $alerta = $this->repo->find($id);
        if ($alerta === null) {
            return $this->json(['error' => 'Alerta no encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if ($alerta->isActiva()) {
            return $this->json(['error' => 'No se puede editar una alerta activa. Desactivala primero.'], Response::HTTP_CONFLICT);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (isset($body['titulo']))    $alerta->setTitulo(trim($body['titulo']));
        if (isset($body['mensaje']))   $alerta->setMensaje(trim($body['mensaje']));
        if (isset($body['prioridad'])) $alerta->setPrioridad((int) $body['prioridad']);
        if (array_key_exists('expira_en', $body)) {
            $alerta->setExpiraEn($body['expira_en'] ? new DateTimeImmutable($body['expira_en']) : null);
        }
        if (array_key_exists('contenido_id', $body)) {
            if ($body['contenido_id'] === null) {
                $alerta->setContenido(null);
            } else {
                $contenido = $this->contenidoRepo->find($body['contenido_id']);
                if ($contenido === null) {
                    return $this->json(['error' => 'Contenido no encontrado.'], Response::HTTP_NOT_FOUND);
                }
                $alerta->setContenido($contenido);
            }
        }

        $this->em()->flush();

        return $this->json(['data' => $this->serialize($alerta)]);
    }

    #[Route('/{id}', name: 'spui_alertas_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $alerta = $this->repo->find($id);
        if ($alerta === null) {
            return $this->json(['error' => 'Alerta no encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if ($alerta->isActiva()) {
            return $this->json(['error' => 'No se puede eliminar una alerta activa. Desactivala primero.'], Response::HTTP_CONFLICT);
        }

        $em = $this->em();
        $em->remove($alerta);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Acciones de estado
    // -------------------------------------------------------------------------

    /**
     * Activa la alerta → interrumpe la programación normal en TODOS los reproductores.
     * Publica en MQTT (reproductores Pi) y Mercure (dashboard web).
     */
    #[Route('/{id}/activar', name: 'spui_alertas_activar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activar(int $id): JsonResponse
    {
        $alerta = $this->repo->find($id);
        if ($alerta === null) {
            return $this->json(['error' => 'Alerta no encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if ($alerta->isActiva()) {
            return $this->json(['error' => 'La alerta ya está activa.'], Response::HTTP_CONFLICT);
        }
        if ($alerta->haExpirado()) {
            return $this->json(['error' => 'La alerta expiró y no puede activarse.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $alerta->activar();
        $this->em()->flush();

        // Push a reproductores Pi (MQTT) y dashboard web (Mercure)
        $this->publisher->publicarActivacion($alerta);

        return $this->json(['data' => $this->serialize($alerta)]);
    }

    /** Desactiva la alerta → los reproductores vuelven a la programación normal. */
    #[Route('/{id}/desactivar', name: 'spui_alertas_desactivar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function desactivar(int $id): JsonResponse
    {
        $alerta = $this->repo->find($id);
        if ($alerta === null) {
            return $this->json(['error' => 'Alerta no encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if (!$alerta->isActiva()) {
            return $this->json(['error' => 'La alerta ya está inactiva.'], Response::HTTP_CONFLICT);
        }

        $alerta->desactivar();
        $this->em()->flush();

        // Push a reproductores Pi (MQTT) y dashboard web (Mercure)
        $this->publisher->publicarDesactivacion($alerta);

        return $this->json(['data' => $this->serialize($alerta)]);
    }
}
