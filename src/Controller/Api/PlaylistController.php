<?php

declare(strict_types=1);

namespace SPUI\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Playlist;
use SPUI\Entity\PlaylistItem;
use SPUI\Repository\ContenidoRepository;
use SPUI\Repository\PlaylistItemRepository;
use SPUI\Repository\PlaylistRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/spui/playlists')]
class PlaylistController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly PlaylistRepository $repo,
        private readonly PlaylistItemRepository $itemRepo,
        private readonly ContenidoRepository $contenidoRepo,
    ) {}

    private function em(): EntityManagerInterface
    {
        return $this->doctrine->getManager('SPUI');
    }

    private function serializeItem(PlaylistItem $item): array
    {
        $c = $item->getContenido();
        return [
            'id'                   => $item->getId(),
            'orden'                => $item->getOrden(),
            'duracion_override_seg' => $item->getDuracionOverrideSeg(),
            'duracion_efectiva_seg' => $item->getDuracionEfectiva(),
            'contenido'            => [
                'id'     => $c->getId(),
                'titulo' => $c->getTitulo(),
                'tipo'   => $c->getTipo()->value,
            ],
        ];
    }

    private function serialize(Playlist $p, bool $withItems = false): array
    {
        $data = [
            'id'            => $p->getId(),
            'nombre'        => $p->getNombre(),
            'descripcion'   => $p->getDescripcion(),
            'activo'        => $p->isActivo(),
            'creado_por_id' => $p->getCreadoPorId(),
            'items_count'   => $p->getItems()->count(),
            'creado_en'     => $p->getCreadoEn()->format('c'),
            'actualizado_en' => $p->getActualizadoEn()->format('c'),
        ];
        if ($withItems) {
            $data['items'] = array_map(
                $this->serializeItem(...),
                $p->getItems()->toArray(),
            );
        }
        return $data;
    }

    #[Route('', name: 'spui_playlists_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $criteria = $request->query->has('activo')
            ? ['activo' => $request->query->getBoolean('activo')]
            : [];

        $items = $this->repo->findBy($criteria, ['nombre' => 'ASC']);

        return $this->json([
            'data'  => array_map($this->serialize(...), $items),
            'total' => count($items),
        ]);
    }

    #[Route('', name: 'spui_playlists_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        $errors = [];
        if (empty($body['nombre']))        $errors[] = '"nombre" es requerido.';
        if (empty($body['creado_por_id'])) $errors[] = '"creado_por_id" es requerido.';
        if (!empty($errors)) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $playlist = new Playlist();
        $playlist->setNombre(trim($body['nombre']));
        $playlist->setDescripcion(isset($body['descripcion']) ? trim($body['descripcion']) : null);
        $playlist->setActivo((bool) ($body['activo'] ?? true));
        $playlist->setCreadoPorId((int) $body['creado_por_id']);

        $em = $this->em();
        $em->persist($playlist);
        $em->flush();

        return $this->json(['data' => $this->serialize($playlist, true)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spui_playlists_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $playlist = $this->repo->find($id);
        if ($playlist === null) {
            return $this->json(['error' => 'Playlist no encontrada.'], Response::HTTP_NOT_FOUND);
        }
        return $this->json(['data' => $this->serialize($playlist, true)]);
    }

    #[Route('/{id}', name: 'spui_playlists_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $playlist = $this->repo->find($id);
        if ($playlist === null) {
            return $this->json(['error' => 'Playlist no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (isset($body['nombre'])) {
            $playlist->setNombre(trim($body['nombre']));
        }
        if (array_key_exists('descripcion', $body)) {
            $playlist->setDescripcion($body['descripcion'] !== null ? trim($body['descripcion']) : null);
        }
        if (isset($body['activo'])) {
            $playlist->setActivo((bool) $body['activo']);
        }

        $this->em()->flush();

        return $this->json(['data' => $this->serialize($playlist, true)]);
    }

    #[Route('/{id}', name: 'spui_playlists_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $playlist = $this->repo->find($id);
        if ($playlist === null) {
            return $this->json(['error' => 'Playlist no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$playlist->getProgramaciones()->isEmpty()) {
            return $this->json(
                ['error' => 'La playlist está en uso en '.$playlist->getProgramaciones()->count().' programación(es). Desasígnala primero.'],
                Response::HTTP_CONFLICT,
            );
        }

        $em = $this->em();
        $em->remove($playlist);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Items de la playlist
    // -------------------------------------------------------------------------

    #[Route('/{id}/items', name: 'spui_playlists_items_add', methods: ['POST'])]
    public function addItem(int $id, Request $request): JsonResponse
    {
        $playlist = $this->repo->find($id);
        if ($playlist === null) {
            return $this->json(['error' => 'Playlist no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        $errors = [];
        if (empty($body['contenido_id'])) $errors[] = '"contenido_id" es requerido.';
        if (!isset($body['orden']))       $errors[] = '"orden" es requerido.';
        if (!empty($errors)) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $contenido = $this->contenidoRepo->find($body['contenido_id']);
        if ($contenido === null) {
            return $this->json(['error' => 'Contenido no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        // Verificar que el orden no esté ya ocupado en esta playlist
        $ordenExistente = $this->itemRepo->findOneBy(['playlist' => $playlist, 'orden' => (int) $body['orden']]);
        if ($ordenExistente !== null) {
            return $this->json(['error' => 'Ya existe un item con orden '.$body['orden'].' en esta playlist.'], Response::HTTP_CONFLICT);
        }

        $item = new PlaylistItem();
        $item->setPlaylist($playlist);
        $item->setContenido($contenido);
        $item->setOrden((int) $body['orden']);
        $item->setDuracionOverrideSeg(isset($body['duracion_override_seg']) ? (int) $body['duracion_override_seg'] : null);

        $em = $this->em();
        $em->persist($item);
        $em->flush();

        return $this->json(['data' => $this->serializeItem($item)], Response::HTTP_CREATED);
    }

    #[Route('/{id}/items/{itemId}', name: 'spui_playlists_items_update', methods: ['PATCH'])]
    public function updateItem(int $id, int $itemId, Request $request): JsonResponse
    {
        $item = $this->itemRepo->find($itemId);
        if ($item === null || $item->getPlaylist()->getId() !== $id) {
            return $this->json(['error' => 'Item no encontrado en esta playlist.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (isset($body['orden'])) {
            $nuevoOrden = (int) $body['orden'];
            $conflicto = $this->itemRepo->findOneBy(['playlist' => $item->getPlaylist(), 'orden' => $nuevoOrden]);
            if ($conflicto !== null && $conflicto->getId() !== $item->getId()) {
                return $this->json(['error' => 'Ya existe un item con orden '.$nuevoOrden.' en esta playlist.'], Response::HTTP_CONFLICT);
            }
            $item->setOrden($nuevoOrden);
        }
        if (array_key_exists('duracion_override_seg', $body)) {
            $item->setDuracionOverrideSeg($body['duracion_override_seg'] !== null ? (int) $body['duracion_override_seg'] : null);
        }

        $this->em()->flush();

        return $this->json(['data' => $this->serializeItem($item)]);
    }

    #[Route('/{id}/items/{itemId}', name: 'spui_playlists_items_delete', methods: ['DELETE'])]
    public function deleteItem(int $id, int $itemId): JsonResponse
    {
        $item = $this->itemRepo->find($itemId);
        if ($item === null || $item->getPlaylist()->getId() !== $id) {
            return $this->json(['error' => 'Item no encontrado en esta playlist.'], Response::HTTP_NOT_FOUND);
        }

        $em = $this->em();
        $em->remove($item);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
