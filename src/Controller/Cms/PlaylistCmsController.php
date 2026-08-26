<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Playlist;
use SPUI\Entity\PlaylistItem;
use SPUI\Repository\ContenidoRepository;
use SPUI\Repository\PantallaRepository;
use SPUI\Repository\PlaylistItemRepository;
use SPUI\Repository\PlaylistRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\ComandoPublisherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/playlists')]
class PlaylistCmsController extends AbstractController
{
    use BloqueoOfflineTrait;
    use CsrfProtegidoTrait;

    public function __construct(
        private readonly PlaylistRepository $repo,
        private readonly ContenidoRepository $contenidoRepo,
        private readonly PlaylistItemRepository $itemRepo,
        private readonly PantallaRepository $pantallaRepo,
        private readonly AlcanceReproductorService $alcance,
        private readonly ComandoPublisherService $comandoPublisher,
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
            'playlists'   => $this->repo->findBy([], ['nombre' => 'ASC']),
            'ids_offline' => $this->alcance->idsOfflineDeUnaVez(),
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

            $reproductores = $this->alcance->dePlaylist($playlist);
            if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_playlists_index')) {
                return $r;
            }

            $playlist->setNombre($nombre);
            $playlist->setDescripcion(trim($request->request->get('descripcion', '')) ?: null);
            $playlist->setActivo((bool) $request->request->get('activo', false));
            $this->em()->flush();
            $this->comandoPublisher->pedirSyncAhora($reproductores, 'playlist');

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
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        // Las tres cosas que referencian una playlist se comprueban antes de
        // borrar. Sin esto, la base rechaza el DELETE por integridad
        // referencial y el operador ve un error SQL crudo —
        // "Cannot delete or update a parent row" — que no dice qué hacer.
        $bloqueos = [];

        if ($playlist->getItems()->count() > 0) {
            $bloqueos[] = sprintf(
                'tiene %d ítem(s): quitalos desde "Ver ítems"',
                $playlist->getItems()->count(),
            );
        }

        if ($playlist->getProgramaciones()->count() > 0) {
            $bloqueos[] = sprintf(
                'la usan %d regla(s) de programación: eliminá o reasigná esas reglas',
                $playlist->getProgramaciones()->count(),
            );
        }

        $pantallasFallback = $this->pantallaRepo->findQueUsanPlaylistComoFallback($playlist);
        if ($pantallasFallback !== []) {
            $nombres = implode(', ', array_map(fn($p) => '"' . $p->getNombre() . '"', $pantallasFallback));
            $bloqueos[] = sprintf(
                'es la playlist de respaldo de %s: cambiala en la edición de esa(s) pantalla(s)',
                $nombres,
            );
        }

        if ($bloqueos !== []) {
            $msg = 'No se puede eliminar "' . $playlist->getNombre() . '" porque '
                 . implode('; y ', $bloqueos) . '.';
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => $msg], 422); }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_playlists_index');
        }

        if ($r = $this->bloquearSiOffline($this->alcance->dePlaylist($playlist), $request, 'spui_cms_playlists_index')) {
            return $r;
        }

        $nombre = $playlist->getNombre();
        $this->em()->remove($playlist);
        $this->em()->flush();

        $msg = '"' . $nombre . '" eliminada.';
        if ($request->isXmlHttpRequest()) { return $this->json(['success' => true, 'message' => $msg]); }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_playlists_index');
    }

    /** Detalle de la playlist (modal "Ver"). */
    #[Route('/{id}/ver', name: 'spui_cms_playlists_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $playlist = $this->repo->find($id);
        if (!$playlist) { throw $this->createNotFoundException(); }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => $playlist->getNombre(),
                'html'  => $this->renderView('@SPUI/playlists/_view.html.twig', ['playlist' => $playlist]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_playlists_index');
    }

    /**
     * Gestión de ítems de la playlist (modal de segundo nivel).
     *
     * Con ?ro=1 se renderiza en sólo lectura: es la vista que se abre desde el
     * detalle, donde no corresponde ofrecer agregar ni reordenar.
     */
    #[Route('/{id}/items', name: 'spui_cms_playlists_items', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function items(int $id, Request $request): Response
    {
        $playlist = $this->repo->find($id);
        if (!$playlist) { throw $this->createNotFoundException(); }

        // Sólo los publicados: un contenido en borrador no puede reproducirse,
        // así que ofrecerlo en el selector induciría al error.
        $disponibles = $this->contenidoRepo->findBy(
            ['estado' => \SPUI\Enum\EstadoContenido::Publicado],
            ['titulo' => 'ASC'],
        );
        $enPlaylist  = array_map(fn($item) => $item->getContenido()->getId(), $playlist->getItems()->toArray());
        $disponibles = array_values(array_filter($disponibles, fn($c) => !in_array($c->getId(), $enPlaylist, true)));

        $html = $this->renderView('@SPUI/playlists/_items.html.twig', [
            'playlist'    => $playlist,
            'disponibles' => $disponibles,
            'readonly'    => $request->query->getBoolean('ro'),
        ]);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['title' => 'Ítems de "' . $playlist->getNombre() . '"', 'html' => $html]);
        }

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
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $reproductores = $this->alcance->dePlaylist($playlist);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_playlists_index')) {
            return $r;
        }

        // MAX(orden)+1 y no count()+1: con huecos en la numeración (por ejemplo
        // tras quitar un ítem del medio) contar daría un orden ya ocupado y
        // reventaría contra el UNIQUE (playlist_id, orden).
        $siguiente = 1;
        foreach ($playlist->getItems() as $existente) {
            $siguiente = max($siguiente, $existente->getOrden() + 1);
        }

        $item = new PlaylistItem();
        $item->setPlaylist($playlist);
        $item->setContenido($contenido);
        $item->setOrden($siguiente);

        $this->em()->persist($item);
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'playlist');

        $msg = '"' . $contenido->getTitulo() . '" agregado a la playlist.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_playlists_index');
    }

    #[Route('/{id}/items/{itemId}/quitar', name: 'spui_cms_playlists_items_quitar', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function quitarItem(int $id, int $itemId, Request $request): Response
    {
        $playlist = $this->repo->find($id);
        $item     = $this->itemRepo->find($itemId);

        if (!$playlist || !$item || $item->getPlaylist()->getId() !== $id) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $reproductores = $this->alcance->dePlaylist($playlist);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_playlists_index')) {
            return $r;
        }

        $titulo = $item->getContenido()->getTitulo();

        $em = $this->em();
        $em->remove($item);
        $em->flush();

        $this->renumerarItems($playlist);
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'playlist');

        $msg = '"' . $titulo . '" quitado de la playlist.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_playlists_index');
    }

    #[Route('/{id}/items/reorder', name: 'spui_cms_playlists_items_reorder', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reorderItems(int $id, Request $request): JsonResponse
    {
        $playlist = $this->repo->find($id);
        if (!$playlist) { return $this->json(['error' => 'Not found'], 404); }

        $reproductores = $this->alcance->dePlaylist($playlist);
        if ($motivo = $this->alcance->bloqueoPara($reproductores)) {
            return $this->json(['success' => false, 'message' => $motivo, 'type' => 'warning'], 409);
        }

        $orden = $request->toArray()['orden'] ?? [];
        $em    = $this->em();

        // Dos pasadas dentro de una transacción. Asignar las posiciones finales
        // de una sola vez choca contra el UNIQUE (playlist_id, orden): InnoDB lo
        // valida fila por fila, así que intercambiar dos ítems produce una
        // colisión transitoria aunque el estado final sea válido. Corriéndolos
        // primero a un rango libre, ninguna posición intermedia se pisa.
        $em->wrapInTransaction(function () use ($orden, $id, $em) {
            foreach ($orden as $posicion => $itemId) {
                $item = $this->itemRepo->find((int) $itemId);
                if ($item && $item->getPlaylist()->getId() === $id) {
                    $item->setOrden($posicion + 1001);
                }
            }
            $em->flush();

            foreach ($orden as $posicion => $itemId) {
                $item = $this->itemRepo->find((int) $itemId);
                if ($item && $item->getPlaylist()->getId() === $id) {
                    $item->setOrden($posicion + 1);
                }
            }
            $em->flush();
        });

        $this->comandoPublisher->pedirSyncAhora($reproductores, 'playlist');

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
