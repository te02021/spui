<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Contenido;
use SPUI\Enum\EstadoContenido;
use SPUI\Enum\TipoContenido;
use SPUI\Form\ContenidoType;
use SPUI\Repository\CodigoQrRepository;
use SPUI\Repository\ContenidoRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\ComandoPublisherService;
use SPUI\Service\MediaStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/contenidos')]
class ContenidoCmsController extends AbstractController
{
    use BloqueoOfflineTrait;
    use CsrfProtegidoTrait;
    use LimiteSubidaTrait;

    private const TIPOS_ARCHIVO = ['imagen', 'video'];

    public function __construct(
        private readonly ContenidoRepository $repo,
        private readonly CodigoQrRepository $codigoQrRepo,
        private readonly AlcanceReproductorService $alcance,
        private readonly ComandoPublisherService $comandoPublisher,
        private readonly ManagerRegistry $doctrine,
        private readonly MediaStorageService $media,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    /** Subcarpeta de MediaStorageService que corresponde a cada tipo de archivo. */
    private static function categoriaDeTipo(string $tipoValue): string
    {
        return $tipoValue === 'video' ? 'videos' : 'imagenes';
    }

    /**
     * Códigos QR ofrecidos en el formulario de contenido.
     *
     * Sólo los activos: un código dado de baja no debería poder asociarse a
     * contenido nuevo, porque su redirect ya devuelve 410.
     */
    private function codigosQrDisponibles(): array
    {
        return $this->codigoQrRepo->findBy(['activo' => true], ['etiqueta' => 'ASC']);
    }

    #[Route('', name: 'spui_cms_contenidos_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/contenidos/index.html.twig', [
            'contenidos'  => $this->repo->findBy([], ['titulo' => 'ASC']),
            'ids_offline' => $this->alcance->idsOfflineDeUnaVez(),
        ]);
    }

    #[Route('/nuevo', name: 'spui_cms_contenidos_new', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        $contenido = new Contenido();
        $form      = $this->createForm(ContenidoType::class, $contenido, [
            'action' => $this->generateUrl('spui_cms_contenidos_new'),
        ]);
        $form->handleRequest($request);

        // ANTES de mirar el form: si el body se descartó por tamaño, todos
        // los campos (título incluido) llegan vacíos y el form tiraría
        // "este campo no puede estar vacío" en cada uno — un mensaje que no
        // tiene nada que ver con la causa real. Ver LimiteSubidaTrait.
        if ($request->isMethod('POST') && ($errLimite = $this->excedioLimiteSubida($request))) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $errLimite, 'type' => 'error'], 422);
            }
            $this->addFlash('error', $errLimite);
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $tipo = $contenido->getTipo();
            $contenido->setEstado(EstadoContenido::Borrador);
            $contenido->setCreadoPorId((int) $this->getUser()->getId());

            if (in_array($tipo->value, self::TIPOS_ARCHIVO, true)) {
                $archivo = $request->files->get('archivo');
                if (!$archivo) {
                    $errMsg = 'El archivo es requerido para el tipo "' . $tipo->value . '".';
                    if ($request->isXmlHttpRequest()) {
                        // Sin 'html' a propósito: si viniera junto con 'html', el JS
                        // del modal (modal.js) sólo reemplaza el cuerpo y nunca
                        // muestra 'message' — quedaría en silencio. Ver el mismo
                        // comentario en AlertaCmsController::aplicarSonido().
                        return $this->json(['success' => false, 'message' => $errMsg, 'type' => 'error']);
                    }
                    $this->addFlash('error', $errMsg);
                    return $this->render('@SPUI/contenidos/new.html.twig', ['form' => $form]);
                }
                if ($errValidacion = $this->media->validar($archivo)) {
                    if ($request->isXmlHttpRequest()) {
                        return $this->json(['success' => false, 'message' => $errValidacion, 'type' => 'error']);
                    }
                    $this->addFlash('error', $errValidacion);
                    return $this->render('@SPUI/contenidos/new.html.twig', ['form' => $form]);
                }
                $hash = $this->media->hashArchivoSubido($archivo);
                $ruta = $this->media->guardar($archivo, self::categoriaDeTipo($tipo->value));
                $contenido->setRutaArchivo($ruta);
                $contenido->setHashArchivo($hash);
            } elseif ($tipo === TipoContenido::Cronograma) {
                // El cronograma no requiere archivo ni texto — los ítems se gestionan en el builder
            } else {
                $texto = trim($request->request->get('contenido_texto', ''));
                if (!$texto) {
                    $errMsg = 'El campo de texto/URL es requerido para este tipo.';
                    if ($request->isXmlHttpRequest()) {
                        return $this->json(['success' => false, 'message' => $errMsg, 'type' => 'error']);
                    }
                    $this->addFlash('error', $errMsg);
                    return $this->render('@SPUI/contenidos/new.html.twig', ['form' => $form]);
                }
                $contenido->setContenidoTexto($texto);
            }

            $this->em()->persist($contenido);
            $this->em()->flush();

            if ($tipo === TipoContenido::Cronograma) {
                $builderUrl = $this->generateUrl('spui_cms_cronograma_builder', ['id' => $contenido->getId()]);
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => true, 'redirect' => $builderUrl]);
                }
                $this->addFlash('success', 'Cronograma "' . $contenido->getTitulo() . '" creado. Ahora agregá los ítems.');
                return $this->redirect($builderUrl);
            }

            $msg = 'Contenido "' . $contenido->getTitulo() . '" creado correctamente.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nuevo contenido',
                'html'  => $this->renderView('@SPUI/contenidos/_form.html.twig', ['form' => $form, 'codigos_qr' => $this->codigosQrDisponibles()]),
            ]);
        }

        return $this->render('@SPUI/contenidos/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/editar', name: 'spui_cms_contenidos_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $c = $this->repo->find($id);
        if (!$c) { throw $this->createNotFoundException(); }

        if ($request->isMethod('POST')) {
            // ANTES que cualquier campo: si el body se descartó por tamaño,
            // 'titulo' llega vacío igual que si no se hubiera tipeado nada —
            // sin este chequeo el error decía "El título es requerido" con
            // el título bien escrito. Ver LimiteSubidaTrait.
            if ($errLimite = $this->excedioLimiteSubida($request)) {
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'html' => $this->renderView('@SPUI/contenidos/_edit_form.html.twig', ['contenido' => $c, 'error' => $errLimite])]);
                }
                $this->addFlash('error', $errLimite);
                return $this->redirectToRoute('spui_cms_contenidos_index');
            }

            $titulo = trim($request->request->get('titulo', ''));
            if (!$titulo) {
                $error = 'El título es requerido.';
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'html' => $this->renderView('@SPUI/contenidos/_edit_form.html.twig', ['contenido' => $c, 'error' => $error])]);
                }
                $this->addFlash('error', $error);
                return $this->redirectToRoute('spui_cms_contenidos_index');
            }

            $c->setTitulo($titulo);
            $durStr = $request->request->get('duracion_segundos', '');
            $c->setDuracionSegundos($durStr !== '' ? max(1, (int) $durStr) : null);

            if (in_array($c->getTipo()->value, ['texto', 'youtube', 'qr'], true)) {
                $texto = trim($request->request->get('contenido_texto', ''));
                if ($texto !== '') { $c->setContenidoTexto($texto); }
            }

            if (in_array($c->getTipo()->value, self::TIPOS_ARCHIVO, true)) {
                $archivo = $request->files->get('archivo');
                if ($archivo) {
                    if ($errValidacion = $this->media->validar($archivo)) {
                        if ($request->isXmlHttpRequest()) {
                            return $this->json(['success' => false, 'html' => $this->renderView('@SPUI/contenidos/_edit_form.html.twig', ['contenido' => $c, 'error' => $errValidacion])]);
                        }
                        $this->addFlash('error', $errValidacion);
                        return $this->redirectToRoute('spui_cms_contenidos_index');
                    }
                    // Se borra el viejo DESPUÉS de guardar el nuevo, no antes: si
                    // guardar() falla (S3 caído, disco lleno), el contenido no se
                    // queda sin ningún archivo.
                    $anterior = $c->getRutaArchivo();
                    $hash = $this->media->hashArchivoSubido($archivo);
                    $ruta = $this->media->guardar($archivo, self::categoriaDeTipo($c->getTipo()->value));
                    $c->setRutaArchivo($ruta);
                    $c->setHashArchivo($hash);
                    $this->media->borrar($anterior);
                }
            }

            $this->em()->flush();
            $this->comandoPublisher->pedirSyncAhora($this->alcance->deContenido($c), 'contenido');
            $msg = '"' . $c->getTitulo() . '" actualizado.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $c->getTitulo(),
                'html'  => $this->renderView('@SPUI/contenidos/_edit_form.html.twig', ['contenido' => $c, 'error' => null]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    /** Detalle del contenido (modal "Ver"). */
    #[Route('/{id}/ver', name: 'spui_cms_contenidos_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $contenido = $this->repo->find($id);
        if (!$contenido) {
            throw $this->createNotFoundException();
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => $contenido->getTitulo(),
                'html'  => $this->renderView('@SPUI/contenidos/_view.html.twig', ['contenido' => $contenido]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    /**
     * Sirve el archivo de un Contenido (imagen/video) para el visor del CMS —
     * tanto la miniatura chica de los modales Ver/Editar como el visor a
     * pantalla completa. Ruta aparte de /api/spui/media/{filename}: esa exige
     * X-Api-Key (es para el Pi), acá alcanza con la sesión normal del
     * firewall de /spui/... . Mismo patrón que
     * AlertaCmsController::sonidoPreview().
     */
    #[Route('/{id}/archivo-preview', name: 'spui_cms_contenidos_archivo_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function archivoPreview(int $id): Response
    {
        $contenido = $this->repo->find($id);
        if (!$contenido || $contenido->getRutaArchivo() === null) {
            throw $this->createNotFoundException();
        }

        $binario = $this->media->leer($contenido->getRutaArchivo());
        if ($binario === null) {
            throw $this->createNotFoundException();
        }

        $response = new Response($binario, Response::HTTP_OK);
        $response->headers->set('Content-Type', $this->media->tipoMimePorExtension($contenido->getRutaArchivo()));
        $response->setEtag($contenido->getHashArchivo() ?? hash('sha256', $binario));
        $response->setPublic();

        return $response;
    }

    /**
     * Vuelve un contenido publicado a borrador.
     *
     * Sacarlo de circulación sin archivarlo: deja de aparecer en el selector de
     * playlists, pero sigue editable para volver a publicarlo.
     */
    #[Route('/{id}/despublicar', name: 'spui_cms_contenidos_despublicar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function despublicar(int $id, Request $request): Response
    {
        $c = $this->repo->find($id);
        if (!$c) {
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => 'Contenido no encontrado.'], 404); }
            $this->addFlash('error', 'Contenido no encontrado.');
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $reproductores = $this->alcance->deContenido($c);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_contenidos_index')) {
            return $r;
        }

        $c->setEstado(EstadoContenido::Borrador);
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'contenido');
        $msg = '"' . $c->getTitulo() . '" volvió a borrador.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    #[Route('/{id}/publicar', name: 'spui_cms_contenidos_publicar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publicar(int $id, Request $request): Response
    {
        $c = $this->repo->find($id);
        if (!$c) {
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => 'Contenido no encontrado.'], 404); }
            $this->addFlash('error', 'Contenido no encontrado.');
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $reproductores = $this->alcance->deContenido($c);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_contenidos_index')) {
            return $r;
        }

        $c->setEstado(EstadoContenido::Publicado);
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'contenido');
        $msg = '"' . $c->getTitulo() . '" publicado.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    #[Route('/{id}/archivar', name: 'spui_cms_contenidos_archivar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archivar(int $id, Request $request): Response
    {
        $c = $this->repo->find($id);
        if (!$c) {
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => 'Contenido no encontrado.'], 404); }
            $this->addFlash('error', 'Contenido no encontrado.');
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $reproductores = $this->alcance->deContenido($c);
        if ($r = $this->bloquearSiOffline($reproductores, $request, 'spui_cms_contenidos_index')) {
            return $r;
        }

        $c->setEstado(EstadoContenido::Archivado);
        $this->em()->flush();
        $this->comandoPublisher->pedirSyncAhora($reproductores, 'contenido');
        $msg = '"' . $c->getTitulo() . '" archivado.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg, 'type' => 'warning']);
        }
        $this->addFlash('warning', $msg);
        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_contenidos_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $c = $this->repo->find($id);
        if (!$c) {
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => 'Contenido no encontrado.'], 404); }
            $this->addFlash('error', 'Contenido no encontrado.');
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        // Se comprueban las dos referencias que impiden el borrado. Faltaba la
        // de alertas: sin ella el DELETE moría con un error SQL de integridad
        // referencial, ilegible para el operador.
        // Los cronograma_item no hacen falta acá: su FK es ON DELETE CASCADE,
        // así que se van con el contenido, que es lo correcto (son sus filas).
        $bloqueos = [];

        if (!$c->getPlaylistItems()->isEmpty()) {
            $bloqueos[] = 'está en uso en ' . $c->getPlaylistItems()->count() . ' playlist(s): retiralo primero';
        }

        if (!$c->getAlertas()->isEmpty()) {
            $bloqueos[] = 'lo usan ' . $c->getAlertas()->count() . ' alerta(s): cambiá o eliminá esas alertas';
        }

        if ($bloqueos !== []) {
            $msg = 'No se puede eliminar "' . $c->getTitulo() . '" porque ' . implode('; y ', $bloqueos) . '.';
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => $msg], 422); }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        $titulo = $c->getTitulo();
        // El archivo se borra DESPUÉS de confirmar el remove: si algo fallara
        // antes, no queremos haber borrado el archivo de un contenido que
        // sigue existiendo. No estaba pasando en absoluto — quedaban huérfanos
        // para siempre pese a lo que decía la documentación.
        $rutaArchivo = $c->getRutaArchivo();
        $this->em()->remove($c);
        $this->em()->flush();
        $this->media->borrar($rutaArchivo);
        $msg = '"' . $titulo . '" eliminado.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_contenidos_index');
    }
}
