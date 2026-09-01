<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\AlertaEmergencia;
use SPUI\Form\AlertaType;
use SPUI\Repository\AlertaEmergenciaRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\AlertaPublisherService;
use SPUI\Service\MediaStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/alertas')]
class AlertaCmsController extends AbstractController
{
    use BloqueoOfflineTrait;
    use CsrfProtegidoTrait;
    use LimiteSubidaTrait;

    /**
     * Sólo mp3/wav — evita la ambigüedad de .ogg (audio vs video, ver
     * MediaController::tipoMime) y alcanza para un clip corto de alarma.
     *
     * Validación propia y no MediaStorageService::validar(): ese método sólo
     * admite imagen/video (el sistema no reproduce audio suelto en pantalla),
     * con límites pensados para eso — un video institucional puede pesar
     * mucho más que el clip corto de una alarma.
     */
    private const SONIDO_MIME_PERMITIDOS = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/wave'];
    private const SONIDO_EXTENSIONES_PERMITIDAS = ['mp3', 'wav'];
    private const SONIDO_TAMANIO_MAXIMO = 5 * 1024 * 1024;   // 5 MB — un clip de alarma es corto

    public function __construct(
        private readonly AlertaEmergenciaRepository $repo,
        private readonly AlertaPublisherService $publisher,
        private readonly AlcanceReproductorService $alcance,
        private readonly ManagerRegistry $doctrine,
        private readonly MediaStorageService $media,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    /**
     * Aplica el archivo de sonido subido (si vino) o lo quita (si se marcó
     * "sonido_quitar"). No toca nada si no vino ninguna de las dos cosas —
     * así una edición que no toca el sonido no lo borra por accidente.
     *
     * @return string|null Mensaje de error en español, o null si quedó aplicado.
     */
    private function aplicarSonido(AlertaEmergencia $alerta, Request $request): ?string
    {
        $archivo = $request->files->get('sonido_archivo');

        if ($archivo instanceof UploadedFile) {
            if (!$archivo->isValid()) {
                return 'El archivo de sonido no se subió correctamente. Probá de nuevo.';
            }
            if ($archivo->getSize() > self::SONIDO_TAMANIO_MAXIMO) {
                return 'El archivo de sonido pesa más de 5 MB. Usá un clip más corto.';
            }
            $mime = $archivo->getMimeType();
            if (!in_array($mime, self::SONIDO_MIME_PERMITIDOS, true)) {
                return 'Formato de sonido no admitido. Se aceptan MP3 y WAV.';
            }
            $ext = strtolower($archivo->guessExtension() ?? $archivo->getClientOriginalExtension() ?? '');
            if (!in_array($ext, self::SONIDO_EXTENSIONES_PERMITIDAS, true)) {
                return 'Formato de sonido no admitido. Se aceptan MP3 y WAV.';
            }

            // Se guarda ANTES de borrar el viejo: si el almacenamiento falla
            // (S3 caído, disco lleno), la alerta no se queda sin sonido.
            //
            // sonidoArchivo guarda la ruta relativa completa
            // ('uploads/spui/alertas/<nombre>'), igual formato que
            // Contenido::rutaArchivo — así MediaStorageService::rutaParaUrl()
            // sabe en qué subcarpeta buscarlo sin adivinar nada.
            $anterior = $alerta->getSonidoArchivo();
            $hash     = $this->media->hashArchivoSubido($archivo);
            $ruta     = $this->media->guardar($archivo, 'alertas');

            $alerta->setSonidoArchivo($ruta);
            $alerta->setSonidoHashArchivo($hash);
            $alerta->setSonidoNombreOriginal($archivo->getClientOriginalName());

            $this->borrarSonido($anterior);

            return null;
        }

        if ($request->request->getBoolean('sonido_quitar')) {
            $this->borrarSonido($alerta->getSonidoArchivo());
            $alerta->setSonidoArchivo(null);
            $alerta->setSonidoHashArchivo(null);
            $alerta->setSonidoNombreOriginal(null);
        }

        return null;
    }

    private function borrarSonido(?string $rutaRelativa): void
    {
        $this->media->borrar($rutaRelativa);
    }

    #[Route('', name: 'spui_cms_alertas_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/alertas/index.html.twig', [
            'alertas' => $this->repo->findBy([], ['activa' => 'DESC', 'prioridad' => 'DESC', 'creadaEn' => 'DESC']),
        ]);
    }

    #[Route('/nueva', name: 'spui_cms_alertas_nueva_form', methods: ['GET', 'POST'])]
    public function nueva(Request $request): Response
    {
        $alerta = new AlertaEmergencia();
        $alerta->setPrioridad(10);
        $form = $this->createForm(AlertaType::class, $alerta, [
            'action' => $this->generateUrl('spui_cms_alertas_nueva_form'),
        ]);
        $form->handleRequest($request);

        if ($request->isMethod('POST') && ($errLimite = $this->excedioLimiteSubida($request))) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $errLimite, 'type' => 'error'], 422);
            }
            $this->addFlash('error', $errLimite);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if ($errorSonido = $this->aplicarSonido($alerta, $request)) {
                // Sin 'html' a propósito: el JS del modal sólo muestra el
                // flash con 'message' cuando la respuesta NO trae 'html' (si
                // trae las dos, sólo re-renderiza el form y el mensaje se
                // pierde en silencio — el archivo es un campo sin mapear al
                // form, así que no hay dónde mostrar un error inline).
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'message' => $errorSonido, 'type' => 'error'], 422);
                }
                $this->addFlash('error', $errorSonido);
                return $this->redirectToRoute('spui_cms_alertas_index');
            }

            $alerta->setCreadoPorId((int) $this->getUser()->getId());
            $this->em()->persist($alerta);
            $this->em()->flush();
            $msg = 'Alerta "' . $alerta->getTitulo() . '" creada.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nueva alerta',
                'html'  => $this->renderView('@SPUI/alertas/_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    /** Detalle completo de la alerta (modal "Ver"). */
    #[Route('/{id}/ver', name: 'spui_cms_alertas_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta) {
            throw $this->createNotFoundException();
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Alerta: ' . $alerta->getTitulo(),
                'html'  => $this->renderView('@SPUI/alertas/_view.html.twig', ['alerta' => $alerta]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    /**
     * Editar una alerta. Sólo si está inactiva: cambiarle el texto mientras se
     * está mostrando en las pantallas dejaría al CMS y a los nodos desincronizados
     * (el push por MQTT ya salió con el contenido viejo).
     */
    #[Route('/{id}/editar', name: 'spui_cms_alertas_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta) {
            throw $this->createNotFoundException();
        }

        if ($alerta->isActiva()) {
            $msg = 'No se puede editar una alerta activa. Desactivala primero.';
            if ($request->isXmlHttpRequest()) {
                // En POST el JS espera {success:false}; en GET espera {title,html},
                // así que se responde 200 con el motivo dentro del modal.
                if ($request->isMethod('POST')) {
                    return $this->json(['success' => false, 'message' => $msg, 'type' => 'warning'], 422);
                }
                return $this->json([
                    'title' => 'Alerta activa',
                    'html'  => '<div class="alert alert-warning mb-0">' . $msg . '</div>'
                             . '<div class="d-flex justify-content-end pt-3 mt-3 border-top">'
                             . '<button type="button" class="unraf-btn spui-btn-back" data-spui-close>Entendido</button></div>',
                ]);
            }
            $this->addFlash('warning', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        $form = $this->createForm(AlertaType::class, $alerta, [
            'action' => $this->generateUrl('spui_cms_alertas_editar', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($request->isMethod('POST') && ($errLimite = $this->excedioLimiteSubida($request))) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $errLimite, 'type' => 'error'], 422);
            }
            $this->addFlash('error', $errLimite);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if ($errorSonido = $this->aplicarSonido($alerta, $request)) {
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'message' => $errorSonido, 'type' => 'error'], 422);
                }
                $this->addFlash('error', $errorSonido);
                return $this->redirectToRoute('spui_cms_alertas_index');
            }

            $this->em()->flush();
            $msg = 'Alerta "' . $alerta->getTitulo() . '" actualizada.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $alerta->getTitulo(),
                'html'  => $this->renderView('@SPUI/alertas/_form.html.twig', ['form' => $form, 'alerta' => $alerta, 'modo' => 'edicion']),
            ]);
        }

        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    #[Route('/{id}/activar', name: 'spui_cms_alertas_activar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activar(int $id, Request $request): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta) { throw $this->createNotFoundException(); }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        if ($alerta->isActiva()) {
            $msg = 'La alerta ya está activa.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg, 'type' => 'warning'], 422);
            }
            $this->addFlash('warning', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }
        if ($alerta->haExpirado()) {
            $msg = 'La alerta expiró y no puede activarse.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg], 422);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        if ($r = $this->bloquearSiOffline($this->alcance->deAlerta($alerta), $request, 'spui_cms_alertas_index')) {
            return $r;
        }

        $alerta->activar();
        $this->em()->flush();
        $this->publisher->publicarActivacion($alerta, $request->getSchemeAndHttpHost());
        $msg = 'Alerta "' . $alerta->getTitulo() . '" ACTIVADA. Reproductores notificados vía MQTT.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    #[Route('/{id}/desactivar', name: 'spui_cms_alertas_desactivar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function desactivar(int $id, Request $request): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta) { throw $this->createNotFoundException(); }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        if (!$alerta->isActiva()) {
            $msg = 'La alerta ya está inactiva.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg, 'type' => 'warning'], 422);
            }
            $this->addFlash('warning', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        if ($r = $this->bloquearSiOffline($this->alcance->deAlerta($alerta), $request, 'spui_cms_alertas_index')) {
            return $r;
        }

        $alerta->desactivar();
        $this->em()->flush();
        $this->publisher->publicarDesactivacion($alerta);
        $msg = 'Alerta "' . $alerta->getTitulo() . '" desactivada.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_alertas_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta) { throw $this->createNotFoundException(); }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        if ($alerta->isActiva()) {
            $msg = 'No se puede eliminar una alerta activa. Desactivala primero.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg], 422);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_alertas_index');
        }

        $titulo = $alerta->getTitulo();
        $this->borrarSonido($alerta->getSonidoArchivo());
        $this->em()->remove($alerta);
        $this->em()->flush();
        $msg = 'Alerta "' . $titulo . '" eliminada.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_alertas_index');
    }

    /**
     * Escucha el sonido subido antes de activar la alerta. Ruta aparte de
     * /api/spui/media/{filename}: esa exige X-Api-Key (es para el Pi), el
     * navegador del CMS no la tiene — acá alcanza con la sesión normal del
     * firewall de /spui/... .
     */
    #[Route('/{id}/sonido-preview', name: 'spui_cms_alertas_sonido_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sonidoPreview(int $id): Response
    {
        $alerta = $this->repo->find($id);
        if (!$alerta || $alerta->getSonidoArchivo() === null) {
            throw $this->createNotFoundException();
        }

        $rutaRelativa = $alerta->getSonidoArchivo();

        // No BinaryFileResponse: eso sólo sirve un archivo del disco local, y
        // con S3 el sonido no vive ahí. media->leer() abstrae los dos casos
        // igual que ya hace MediaController para la Pi — acá el destino es el
        // navegador del CMS, no la Pi, pero el mecanismo es el mismo.
        $contenido = $this->media->leer($rutaRelativa);
        if ($contenido === null) {
            throw $this->createNotFoundException();
        }

        $response = new Response($contenido, Response::HTTP_OK);
        $response->headers->set(
            'Content-Type',
            str_ends_with(strtolower($rutaRelativa), '.wav') ? 'audio/wav' : 'audio/mpeg',
        );
        return $response;
    }
}
