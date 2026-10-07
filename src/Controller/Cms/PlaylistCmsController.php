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
                return $this->formularioConErrores($request, null, ['nombre' => 'El nombre es requerido.']);
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
            if ($this->itemsModificados($request)) {
                $this->sincronizarItems($playlist, $this->idsDeContenidoDelRequest($request));
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
                return $this->formularioConErrores($request, $playlist, ['nombre' => 'El nombre es requerido.']);
            }

            $reproductores = $this->alcance->dePlaylist($playlist);
            if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_playlists_index')) {
                return $r;
            }

            $playlist->setNombre($nombre);
            $playlist->setDescripcion(trim($request->request->get('descripcion', '')) ?: null);
            $playlist->setActivo((bool) $request->request->get('activo', false));
            $this->em()->flush();

            // Los ítems viajan con este mismo Guardar (único punto de guardado):
            // la ventana de ítems sólo los confirma en el navegador. Un solo
            // aviso a las pantallas por los datos y los ítems juntos.
            if ($this->itemsModificados($request)) {
                $this->sincronizarItems($playlist, $this->idsDeContenidoDelRequest($request));
            }
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
                'html'  => $this->renderView('@SPUI/playlists/_edit_form.html.twig', ['playlist' => $playlist]),
            ]);
        }
        return $this->redirectToRoute('spui_cms_playlists_index');
    }

    /**
     * Activar / desactivar desde el listado: el mismo cambio que el switch del
     * formulario. Una playlist inactiva no se reproduce (ni por programación
     * ni como respaldo), así que avisa a las pantallas que la usan.
     */
    #[Route('/{id}/toggle', name: 'spui_cms_playlists_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): Response
    {
        $playlist = $this->repo->find($id);
        if (!$playlist) { throw $this->createNotFoundException(); }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $reproductores = $this->alcance->dePlaylist($playlist);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_playlists_index')) {
            return $r;
        }

        $playlist->setActivo(!$playlist->isActivo());
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'playlist');

        $msg = 'Playlist "' . $playlist->getNombre() . '" ' . ($playlist->isActivo() ? 'activada' : 'desactivada') . '.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
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
     * Deja los ítems de la playlist exactamente como los mandó el navegador.
     *
     * Se llama desde el Guardar de la playlist (alta y edición), que es el
     * único punto de guardado: la ventana de ítems sólo confirma la lista en
     * el navegador. El servidor la reconcilia — alta de los nuevos, baja de
     * los que ya no están, reasignación del orden — en una sola transacción.
     *
     * Un mismo contenido puede aparecer varias veces (para darle más
     * frecuencia dentro del loop), así que los ítems se emparejan como
     * multiconjunto: cada aparición del contenido X en la lista nueva reusa
     * el siguiente ítem existente de X, en orden, y sólo se crean o borran
     * las apariciones que sobran o faltan.
     *
     * @param list<int> $contenidoIds Ids de contenido, en el orden final deseado (con repetidos).
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

            /** @var array<int, list<PlaylistItem>> $porContenido Ítems existentes de cada contenido, en su orden actual. */
            $porContenido = [];
            foreach ($playlist->getItems() as $item) {
                $porContenido[$item->getContenido()->getId()][] = $item;
            }

            // Se ubican en el orden nuevo, reusando ítems existentes del mismo
            // contenido mientras queden; los que falten se crean.
            $orden = 1;
            foreach ($contenidoIds as $contenidoId) {
                $item = isset($porContenido[$contenidoId]) ? array_shift($porContenido[$contenidoId]) : null;

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

            // Las apariciones existentes que no se reusaron son las que el
            // navegador ya no manda: se van.
            foreach ($porContenido as $sobrantes) {
                foreach ($sobrantes as $item) {
                    $playlist->removeItem($item);
                    $em->remove($item);
                }
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

        // Con repetidos a propósito: un contenido puede ir más de una vez.
        return array_values(array_filter(array_map('intval', $crudos)));
    }

    /**
     * Vuelve a dibujar el formulario (alta si $playlist es null, edición si no)
     * con cada error debajo de su campo, conservando lo que se había cargado —
     * incluida la lista de ítems confirmada, que si no se perdería.
     *
     * Sólo 'html', sin 'message': modal.js ignora el message cuando hay html.
     *
     * @param array<string, string> $errores Campo → mensaje.
     */
    private function formularioConErrores(Request $request, ?Playlist $playlist, array $errores): Response
    {
        if (!$request->isXmlHttpRequest()) {
            $this->addFlash('error', implode(' ', $errores));
            return $this->redirectToRoute('spui_cms_playlists_index');
        }

        $vars = [
            'errores'           => $errores,
            'values'            => [
                'nombre'      => trim((string) $request->request->get('nombre', '')),
                'descripcion' => trim((string) $request->request->get('descripcion', '')),
                'activo'      => $request->request->getBoolean('activo'),
            ],
            'items_confirmados' => $this->itemsModificados($request) ? $this->idsDeContenidoDelRequest($request) : null,
        ];

        $html = $playlist === null
            ? $this->renderView('@SPUI/playlists/_form.html.twig', $vars)
            : $this->renderView('@SPUI/playlists/_edit_form.html.twig', $vars + ['playlist' => $playlist]);

        return $this->json(['success' => false, 'html' => $html]);
    }

    /**
     * Si el formulario trae una lista de ítems confirmada en la ventana de
     * ítems. Sin esta marca no se tocan los ítems: editar sólo el nombre de
     * una playlist (sin abrir la ventana) no puede vaciarla.
     */
    private function itemsModificados(Request $request): bool
    {
        return $request->request->getBoolean('items_modificados');
    }
}
