<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

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

#[Route('/spui/playlists')]
class PlaylistCmsController extends AbstractController
{
    public function __construct(
        private readonly PlaylistRepository $repo,
        private readonly ContenidoRepository $contenidoRepo,
        private readonly PlaylistItemRepository $itemRepo,
        private readonly ManagerRegistry $doctrine,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    #[Route('', name: 'spui_cms_playlists_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/playlists/index.html.twig', [
            'playlists' => $this->repo->findBy([], ['nombre' => 'ASC']),
        ]);
    }

    #[Route('/nueva', name: 'spui_cms_playlists_nueva', methods: ['GET', 'POST'])]
    public function nueva(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $nombre = trim($request->request->get('nombre', ''));
            if (!$nombre) {
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'message' => 'El nombre es requerido.']);
                }
                $this->addFlash('error', 'El nombre es requerido.');
                return $this->redirectToRoute('spui_cms_playlists_nueva');
            }

            $playlist = new Playlist();
            $playlist->setNombre($nombre);
            $playlist->setDescripcion(trim($request->request->get('descripcion', '')) ?: null);
            $playlist->setActivo(true);
            $playlist->setCreadoPorId((int) $this->getUser()->getId());

            $this->em()->persist($playlist);
            $this->em()->flush();

            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success'  => true,
                    'message'  => 'Playlist "' . $nombre . '" creada.',
                    'redirect' => $this->generateUrl('spui_cms_playlists_builder', ['id' => $playlist->getId()]),
                ]);
            }

            $this->addFlash('success', 'Playlist "' . $nombre . '" creada.');
            return $this->redirectToRoute('spui_cms_playlists_builder', ['id' => $playlist->getId()]);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nueva playlist',
                'html'  => $this->renderView('@SPUI/playlists/_form.html.twig'),
            ]);
        }

        return $this->render('@SPUI/playlists/nueva.html.twig');
    }

    #[Route('/{id}/editar', name: 'spui_cms_playlists_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $playlist = $this->repo->find($id);
        if (!$playlist) { throw $this->createNotFoundException(); }

        if ($request->isMethod('POST')) {
            $nombre = trim($request->request->get('nombre', ''));
            if (!$nombre) {
                $error = 'El nombre es requerido.';
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'html' => $this->renderView('@SPUI/playlists/_edit_form.html.twig', ['playlist' => $playlist, 'error' => $error])]);
                }
                $this->addFlash('error', $error);
                return $this->redirectToRoute('spui_cms_playlists_index');
            }

            $playlist->setNombre($nombre);
            $playlist->setDescripcion(trim($request->request->get('descripcion', '')) ?: null);
            $playlist->setActivo((bool) $request->request->get('activo', false));
            $this->em()->flush();

            $msg = '"' . $playlist->getNombre() . '" actualizada.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_playlists_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $playlist->getNombre(),
                'html'  => $this->renderView('@SPUI/playlists/_edit_form.html.twig', ['playlist' => $playlist, 'error' => null]),
            ]);
        }
        return $this->redirectToRoute('spui_cms_playlists_index');
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_playlists_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $playlist = $this->repo->find($id);
        if (!$playlist) { throw $this->createNotFoundException(); }

        if ($playlist->getItems()->count() > 0) {
            $msg = 'Primero quitá todos los ítems de la playlist antes de eliminarla.';
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => $msg]); }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_playlists_index');
        }

        $nombre = $playlist->getNombre();
        $this->em()->remove($playlist);
        $this->em()->flush();

        $msg = '"' . $nombre . '" eliminada.';
        if ($request->isXmlHttpRequest()) { return $this->json(['success' => true, 'message' => $msg]); }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_playlists_index');
    }

    #[Route('/{id}/items/agregar-form', name: 'spui_cms_playlists_items_agregar_form', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function agregarItemForm(int $id): Response
    {
        $playlist    = $this->repo->find($id);
        if (!$playlist) { throw $this->createNotFoundException(); }

        $disponibles = $this->contenidoRepo->findBy(['estado' => \SPUI\Enum\EstadoContenido::Publicado], ['titulo' => 'ASC']);
        $enPlaylist  = array_map(fn($item) => $item->getContenido()->getId(), $playlist->getItems()->toArray());
        $disponibles = array_values(array_filter($disponibles, fn($c) => !in_array($c->getId(), $enPlaylist, true)));

        return $this->json([
            'title' => 'Agregar contenido',
            'html'  => $this->renderView('@SPUI/playlists/_agregar_form.html.twig', [
                'playlist'    => $playlist,
                'disponibles' => $disponibles,
            ]),
        ]);
    }

    #[Route('/{id}', name: 'spui_cms_playlists_builder', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function builder(int $id): Response
    {
        $playlist = $this->repo->find($id);
        if (!$playlist) { throw $this->createNotFoundException(); }

        return $this->render('@SPUI/playlists/builder.html.twig', [
            'playlist' => $playlist,
        ]);
    }

    #[Route('/{id}/items/agregar', name: 'spui_cms_playlists_items_agregar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function agregarItem(int $id, Request $request): Response
    {
        $playlist    = $this->repo->find($id);
        $contenidoId = (int) $request->request->get('contenido_id');
        $contenido   = $this->contenidoRepo->find($contenidoId);

        if (!$playlist || !$contenido) { throw $this->createNotFoundException(); }

        $siguiente = count($playlist->getItems()) + 1;

        $item = new PlaylistItem();
        $item->setPlaylist($playlist);
        $item->setContenido($contenido);
        $item->setOrden($siguiente);
        $item->setDuracionOverrideSeg(null);

        $this->em()->persist($item);
        $this->em()->flush();

        $msg        = '"' . $contenido->getTitulo() . '" agregado a la playlist.';
        $builderUrl = $this->generateUrl('spui_cms_playlists_builder', ['id' => $id]);
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg, 'redirect' => $builderUrl]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_playlists_builder', ['id' => $id]);
    }

    #[Route('/{id}/items/{itemId}/quitar', name: 'spui_cms_playlists_items_quitar', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function quitarItem(int $id, int $itemId, Request $request): Response
    {
        $playlist = $this->repo->find($id);
        $item     = $this->itemRepo->find($itemId);

        if (!$playlist || !$item || $item->getPlaylist()->getId() !== $id) {
            throw $this->createNotFoundException();
        }

        $titulo = $item->getContenido()->getTitulo();

        $em = $this->em();
        $em->remove($item);
        $em->flush();

        $this->renumerarItems($playlist);

        $msg        = '"' . $titulo . '" quitado de la playlist.';
        $builderUrl = $this->generateUrl('spui_cms_playlists_builder', ['id' => $id]);
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg, 'redirect' => $builderUrl]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_playlists_builder', ['id' => $id]);
    }

    #[Route('/{id}/items/reorder', name: 'spui_cms_playlists_items_reorder', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reorderItems(int $id, Request $request): JsonResponse
    {
        $playlist = $this->repo->find($id);
        if (!$playlist) { return $this->json(['error' => 'Not found'], 404); }

        $orden = $request->toArray()['orden'] ?? [];
        $em    = $this->em();

        foreach ($orden as $posicion => $itemId) {
            $item = $this->itemRepo->find((int) $itemId);
            if ($item && $item->getPlaylist()->getId() === $id) {
                $item->setOrden($posicion + 1);
            }
        }

        $em->flush();
        return $this->json(['ok' => true]);
    }

    private function renumerarItems(Playlist $playlist): void
    {
        $em = $this->em();
        foreach ($playlist->getItems() as $i => $item) {
            $item->setOrden($i + 1);
        }
        $em->flush();
    }
}
