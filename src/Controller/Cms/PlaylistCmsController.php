<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Playlist;
use SPUI\Entity\PlaylistItem;
use SPUI\Enum\EstadoContenido;
use SPUI\Repository\ContenidoRepository;
use SPUI\Repository\PantallaRepository;
use SPUI\Repository\PlaylistItemRepository;
use SPUI\Repository\PlaylistRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\ComandoPublisherService;
use SPUI\Service\UsuarioResolverService;
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
        private readonly UsuarioResolverService $usuarios,
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
            if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

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

            // Los ítems que se cargaron en el modal antes de guardar. Viajan
            // acá y no se persistieron antes porque un PlaylistItem necesita
            // una playlist ya existente (playlist_id es NOT NULL): recién en
            // este punto hay a qué colgarlos. Si el operador hubiera cerrado
            // el modal sin guardar, no habría quedado nada en la base.
            $iniciales = $this->idsDeContenidoDelRequest($request);
            if ($iniciales !== []) {
                $this->sincronizarItems($playlist, $iniciales);
            }

            // El destino real después de crear es el listado, no un "builder" a
            // pantalla completa: ese diseño se abandonó en algún momento (la
            // plantilla @SPUI/playlists/builder.html.twig nunca llegó a
            // existir; esta ruta reventaba con un "template not found" cada
            // vez que se creaba una playlist).
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success'  => true,
                    'message'  => 'Playlist "' . $nombre . '" creada.',
                    'redirect' => $this->generateUrl('spui_cms_playlists_index'),
                ]);
            }

            $this->addFlash('success', 'Playlist "' . $nombre . '" creada.');
            return $this->redirectToRoute('spui_cms_playlists_index');
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
            if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

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
                'html'  => $this->renderView('@SPUI/playlists/_view.html.twig', [
                    'playlist'         => $playlist,
                    'creado_por_email' => $this->usuarios->email($playlist->getCreadoPorId()),
                ]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_playlists_index');
    }

    /**
     * Catálogo de contenidos que se pueden agregar a una playlist.
     *
     * Sólo los publicados: un contenido en borrador no puede reproducirse, así
     * que ofrecerlo en el selector induciría al error.
     *
     * @return list<\SPUI\Entity\Contenido>
     */
    private function contenidosPublicados(): array
    {
        return $this->contenidoRepo->findBy(
            ['estado' => EstadoContenido::Publicado],
            ['titulo' => 'ASC'],
        );
    }

    /**
     * Lista de ítems para el modal, en ALTA (todavía no hay playlist).
     *
     * Existe como ruta propia porque un PlaylistItem no puede existir sin su
     * playlist (playlist_id es NOT NULL): durante el alta la lista vive sólo
     * en el navegador y se persiste recién con el Guardar del modal principal.
     */
    #[Route('/items/nueva', name: 'spui_cms_playlists_items_nueva', methods: ['GET'])]
    public function itemsNueva(Request $request): Response
    {
        $html = $this->renderView('@SPUI/playlists/_items.html.twig', [
            'playlist'    => null,
            'items'       => [],
            'disponibles' => $this->contenidosPublicados(),
            'readonly'    => false,
        ]);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['title' => 'Ítems de la playlist', 'html' => $html]);
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

        // Todos los publicados, incluidos los que ya están en la playlist: el
        // navegador oculta o muestra cada fila según dónde esté el contenido en
        // ese momento. Filtrarlos acá hacía que un ítem quitado sin guardar no
        // se pudiera volver a agregar, porque su fila nunca llegaba al DOM.
        $html = $this->renderView('@SPUI/playlists/_items.html.twig', [
            'playlist'    => $playlist,
            'items'       => $playlist->getItems(),
            'disponibles' => $this->contenidosPublicados(),
            'readonly'    => $request->query->getBoolean('ro'),
        ]);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['title' => 'Ítems de "' . $playlist->getNombre() . '"', 'html' => $html]);
        }

        return $this->redirectToRoute('spui_cms_playlists_index');
    }

    /**
     * Guarda de una sola vez la lista de ítems tal como quedó en el modal.
     *
     * Reemplaza a los tres endpoints que había antes (agregar / quitar /
     * reorder), que persistían cada acción en el momento: por eso la lista se
     * guardaba sola y no había forma de cancelar. Ahora el navegador acumula
     * los cambios y los manda todos juntos cuando el operador aprieta Guardar.
     */
    #[Route('/{id}/items/guardar', name: 'spui_cms_playlists_items_guardar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function guardarItems(int $id, Request $request): Response
    {
        $playlist = $this->repo->find($id);
        if (!$playlist) { throw $this->createNotFoundException(); }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $reproductores = $this->alcance->dePlaylist($playlist);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_playlists_index')) {
            return $r;
        }

        $this->sincronizarItems($playlist, $this->idsDeContenidoDelRequest($request));
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'playlist');

        $total = $playlist->getItems()->count();
        $msg   = $total === 1 ? '1 ítem guardado.' : $total . ' ítems guardados.';

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }

        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_playlists_index');
    }

    private function renumerarItems(Playlist $playlist): void
    {
        $em = $this->em();
        foreach ($playlist->getItems() as $i => $item) {
            $item->setOrden($i + 1);
        }
        $em->flush();
    }

    /**
     * Deja los ítems de la playlist exactamente como los mandó el navegador.
     *
     * Un solo endpoint en lugar de los tres que había antes (agregar / quitar /
     * reorder): esos persistían cada acción por separado en el momento, y por
     * eso la lista se guardaba sola sin que el operador confirmara nada. Acá el
     * navegador manda la lista completa y el servidor la reconcilia — alta de
     * los nuevos, baja de los que ya no están, reasignación del orden — en una
     * sola transacción y con un solo aviso al reproductor.
     *
     * @param list<int> $contenidoIds Ids de contenido, en el orden final deseado.
     */
    private function sincronizarItems(Playlist $playlist, array $contenidoIds): void
    {
        $em = $this->em();

        $em->wrapInTransaction(function () use ($em, $playlist, $contenidoIds) {
            // Primer paso: correr TODO a un rango libre. El UNIQUE
            // (playlist_id, orden) se valida fila por fila, así que sin esto
            // cualquier intercambio de posiciones choca en un estado
            // intermedio aunque el resultado final sea válido.
            $offset = 1000;
            foreach ($playlist->getItems() as $item) {
                $item->setOrden($offset++);
            }
            $em->flush();

            /** @var array<int, \SPUI\Entity\PlaylistItem> $porContenido */
            $porContenido = [];
            foreach ($playlist->getItems() as $item) {
                $porContenido[$item->getContenido()->getId()] = $item;
            }

            // Los que el navegador ya no manda se van.
            foreach ($porContenido as $contenidoId => $item) {
                if (!in_array($contenidoId, $contenidoIds, true)) {
                    $playlist->getItems()->removeElement($item);
                    $em->remove($item);
                }
            }

            // Los que siguen se reubican; los nuevos se crean.
            $orden = 1;
            foreach ($contenidoIds as $contenidoId) {
                $item = $porContenido[$contenidoId] ?? null;

                if ($item === null) {
                    $contenido = $this->contenidoRepo->find($contenidoId);
                    // Un id que no existe o que dejó de estar publicado se
                    // ignora en silencio: puede haber cambiado entre que se
                    // dibujó la lista y se apretó Guardar, y abortar todo por
                    // eso le haría perder al operador el resto del trabajo.
                    if ($contenido === null || $contenido->getEstado() !== EstadoContenido::Publicado) {
                        continue;
                    }
                    // addItem() y no sólo setPlaylist(): además de colgarlo de
                    // la playlist lo suma a su colección en memoria. Sin eso,
                    // el conteo del mensaje ("N ítems guardados") salía de una
                    // colección desactualizada y decía 0 tras agregar.
                    $item = new PlaylistItem();
                    $item->setContenido($contenido);
                    $playlist->addItem($item);
                    $em->persist($item);
                }

                $item->setOrden($orden++);
            }

            $em->flush();
        });
    }

    /**
     * Ids de contenido que llegan en el POST de guardado de ítems.
     *
     * @return list<int>
     */
    private function idsDeContenidoDelRequest(Request $request): array
    {
        $crudos = $request->request->all('contenido_ids');

        // Sin repetidos: la interfaz no los genera, pero un POST armado a mano
        // podría, y el mismo contenido dos veces en la lista crearía dos ítems.
        return array_values(array_unique(array_filter(array_map('intval', $crudos))));
    }
}
