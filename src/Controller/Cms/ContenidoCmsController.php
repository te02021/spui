<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
use SPUI\Entity\CodigoQr;
use SPUI\Entity\Contenido;
use SPUI\Enum\EstadoContenido;
use SPUI\Enum\TipoContenido;
use SPUI\Form\ContenidoType;
use SPUI\Repository\ContenidoRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\ComandoPublisherService;
use SPUI\Service\ContenidoQrService;
use SPUI\Service\MediaStorageService;
use SPUI\Service\MqttSecurityService;
use SPUI\Service\QrEscaneoPublisherService;
use SPUI\Service\QrGeneratorService;
use SPUI\Service\UsuarioResolverService;
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

    /** Tope de la columna codigo_qr.url_destino. */
    private const QR_URL_MAX = 500;

    public function __construct(
        private readonly ContenidoRepository $repo,
        private readonly AlcanceReproductorService $alcance,
        private readonly ComandoPublisherService $comandoPublisher,
        private readonly ManagerRegistry $doctrine,
        private readonly MediaStorageService $media,
        private readonly ContenidoQrService $contenidoQr,
        private readonly MqttSecurityService $mqttSeguridad,
        private readonly QrGeneratorService $qrGenerator,
        private readonly CacheItemPoolInterface $cache,
        private readonly UsuarioResolverService $usuarios,
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
     * Vuelca en el código QR del contenido lo que vino del formulario, creando
     * el código si el contenido todavía no tiene uno.
     *
     * Los códigos QR se administran desde acá adentro y no desde una sección
     * aparte: un código pertenece al contenido que lo muestra y no se comparte,
     * así que pedir por separado su etiqueta era pedir dos veces el mismo
     * nombre (y dejar que quedaran distintos). La etiqueta sale del título del
     * contenido, y el código queda siempre redirigiendo: para cortarlo está el
     * vencimiento, o eliminar el contenido.
     *
     * @return string|null Mensaje de error en español, o null si salió bien.
     */
    private function aplicarDatosQr(Contenido $contenido, Request $request): ?string
    {
        // InputBag::get() lanza BadRequestException (500) si el valor no es
        // escalar — un qr_url_destino[]=a&qr_url_destino[]=b manual lo
        // convierte en array. El formulario real nunca manda eso, pero un
        // POST armado a mano sí puede, y un 500 técnico viola la regla del
        // proyecto de no mostrar nada de eso al frontend.
        $urlCruda = $request->request->all()['qr_url_destino'] ?? '';
        if (!is_string($urlCruda)) {
            return 'La URL de destino no tiene el formato esperado.';
        }
        $url = trim($urlCruda);
        if ($url === '') {
            return 'La URL de destino es requerida: es a dónde llega quien escanea el código.';
        }

        // Sin esquema se asume https, en vez de rechazar algo que el usuario
        // escribió bien — mismo criterio que tenía el formulario propio de
        // códigos QR con su default_protocol.
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (mb_strlen($url) > self::QR_URL_MAX) {
            return 'La URL de destino no puede superar los ' . self::QR_URL_MAX . ' caracteres.';
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return 'Ingresá una URL válida, que empiece con http:// o https://.';
        }
        // FILTER_VALIDATE_URL es laxo con estos caracteres: los deja pasar
        // dentro del path/query aunque no tengan nada que hacer ahí en una
        // URL real. Hoy Twig auto-escapa donde se muestra (confirmado: un
        // "><script> guardado sale como &lt;script&gt;, no ejecutable), pero
        // que la primera capa de validación los acepte es una URL "válida"
        // que ningún sitio real tendría — y deja la higiene de la entrada
        // apoyada enteramente en que ninguna vista futura use |raw acá.
        if (preg_match('/[<>"\'\x00-\x1f]/', $url)) {
            return 'La URL de destino tiene caracteres que no puede llevar (<, >, comillas).';
        }

        // El <input type="datetime-local"> manda 'Y-m-dTH:i' (algunos
        // navegadores agregan los segundos). El '!' inicial pone en cero todo
        // lo que el formato no nombra: sin él, createFromFormat completa con la
        // hora actual y el vencimiento salía con segundos al azar.
        $expiraCruda = $request->request->all()['qr_expira_en'] ?? '';
        if (!is_string($expiraCruda)) {
            return 'La fecha de vencimiento no tiene el formato esperado.';
        }
        $expiraStr = trim($expiraCruda);
        $expira    = null;
        if ($expiraStr !== '') {
            $expira = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expiraStr)
                   ?: DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $expiraStr);
            if ($expira === false) {
                return 'La fecha de vencimiento no es válida.';
            }
            // Sin este chequeo se aceptaba en silencio una fecha ya pasada: el
            // código quedaba creado pero CodigoQr::estaVigente() lo daba por
            // vencido desde el minuto cero, y quien escaneara el QR recién
            // impreso se encontraba con la pantalla de "código no disponible"
            // sin que el operador entendiera por qué "no funciona".
            if ($expira <= new DateTimeImmutable()) {
                return 'La fecha de vencimiento tiene que ser posterior a ahora.';
            }
        }

        $codigo = $contenido->getCodigoQr() ?? new CodigoQr();
        $codigo->setEtiqueta($contenido->getTitulo())
               ->setUrlDestino($url)
               ->setExpiraEn($expira)
               ->setActivo(true);

        if ($codigo->getId() === null) {
            // El PNG codifica /api/spui/qr/{id}/r, así que el código necesita
            // tener id antes de poder materializarlo.
            $this->em()->persist($codigo);
            $this->em()->flush();
        }

        // Cambiar la URL destino NO obliga a regenerar el PNG: lo que el código
        // codifica es el redirect del CMS, que no cambia. Por eso los QR ya
        // impresos siguen sirviendo. Sólo se genera si todavía no existe.
        if ($contenido->getCodigoQr() !== $codigo || $contenido->getRutaArchivo() === null) {
            $this->contenidoQr->materializar($contenido, $codigo);
        }

        return null;
    }

    /**
     * Respuesta de error del alta de contenido.
     *
     * El camino real del alta es siempre el modal (XHR); el no-XHR queda como
     * salida sana para un POST directo. Antes cada rama de error renderizaba
     * '@SPUI/contenidos/new.html.twig', una plantilla que no existe en este
     * repo: un alta fuera del modal terminaba en un 500 en vez de en el
     * mensaje de error.
     */
    private function errorAlta(Request $request, string $mensaje): Response
    {
        if ($request->isXmlHttpRequest()) {
            // Sin 'html' a propósito: si viniera junto con 'html', el JS del
            // modal (modal.js) sólo reemplaza el cuerpo y nunca muestra
            // 'message' — quedaría en silencio. Ver el mismo comentario en
            // AlertaCmsController::aplicarSonido().
            return $this->json(['success' => false, 'message' => $mensaje, 'type' => 'error']);
        }

        $this->addFlash('error', $mensaje);

        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    /** HTML del formulario de edición, con el aviso de error arriba si lo hay. */
    private function vistaEdicion(Contenido $c, ?string $error): string
    {
        return $this->renderView('@SPUI/contenidos/_edit_form.html.twig', [
            'contenido' => $c,
            'error'     => $error,
        ]);
    }

    /**
     * Respuesta de error de la edición.
     *
     * Manda 'html' sin 'message': el formulario re-renderizado ya trae el aviso
     * inline y modal.js ignora 'message' cuando viene 'html'.
     */
    private function errorEdicion(Request $request, Contenido $c, string $mensaje): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => false, 'html' => $this->vistaEdicion($c, $mensaje)]);
        }

        $this->addFlash('error', $mensaje);

        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    /**
     * Pone al día los PNG de los QR que apuntan a una dirección vieja.
     *
     * Corre acá, en el listado, porque es el único lugar donde se sabe la
     * dirección correcta: la que el operador acaba de usar para entrar. Antes
     * esto era un comando de consola, que no tiene request y por lo tanto
     * tampoco tiene ese dato.
     *
     * Sí, es un GET que puede escribir. Es a propósito y está acotado: la
     * guarda de caché hace que la pasada completa sólo ocurra la primera vez
     * después de que la dirección del CMS cambió de verdad — en producción,
     * donde la base es el dominio, no ocurre nunca. Y la marca de caché se
     * guarda sólo si no falló ninguno, para no dar por hecha una pasada
     * incompleta. Vaciar la caché no rompe nada: se recorre una vez, no se
     * encuentra nada distinto y no se escribe.
     *
     * Sin ningún control manual: no hace falta. El caso frecuente —desarrollo
     * cambiando de red— es justamente el que esto resuelve solo. El caso que
     * sí quedaría afuera —bajar deliberadamente una URL de producción a una
     * IP— es tan raro que no amerita un botón permanente en el panel; si
     * hiciera falta alguna vez, se hace a mano en la base de datos.
     *
     * @return list<Contenido> Los regenerados, para avisarle a los reproductores.
     */
    private function sincronizarQr(): array
    {
        $base = $this->qrGenerator->baseUrl();
        $item = $this->cache->getItem('spui.qr.base_reconciliada');

        if ($item->isHit() && $item->get() === $base) {
            return [];
        }

        $resultado   = $this->contenidoQr->sincronizarUrls();
        $regenerados = $resultado['regenerados'];

        if ($regenerados !== []) {
            $this->em()->flush();

            // Lo que cambió es rutaArchivo y hashArchivo, que es exactamente lo
            // que /sync le manda al reproductor y lo que él usa para decidir si
            // vuelve a descargar. Sin este aviso el cambio tarda hasta el
            // próximo poll y la pantalla sigue mostrando un QR que ya no lleva
            // a ningún lado. Se deduplica: un reproductor que muestra tres
            // contenidos regenerados recibe un solo sync.
            $reproductores = [];
            foreach ($regenerados as $contenido) {
                foreach ($this->alcance->deContenido($contenido) as $r) {
                    $reproductores[$r->getId()] = $r;
                }
            }

            if ($reproductores !== []) {
                $this->comandoPublisher->pedirSyncAhora(array_values($reproductores), 'contenido');
            }
        }

        if ($resultado['fallos'] === 0) {
            $this->cache->save($item->set($base)->expiresAfter(86400));
        }

        return $regenerados;
    }

    #[Route('', name: 'spui_cms_contenidos_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->sincronizarQr();

        $contenidos = $this->repo->findBy([], ['titulo' => 'ASC']);

        // El aviso que antes daba el comando: si la dirección que va a quedar
        // adentro del PNG sólo resuelve desde este equipo, los códigos no los
        // abre ningún celular. Sólo molesta si hay algún QR que mostrar.
        $hayQr = false;
        foreach ($contenidos as $c) {
            if ($c->getTipo() === TipoContenido::Qr) { $hayQr = true; break; }
        }

        return $this->render('@SPUI/contenidos/index.html.twig', [
            'contenidos'    => $contenidos,
            'ids_offline'   => $this->alcance->idsOfflineDeUnaVez(),
            'hay_qr'        => $hayQr,
            'qr_inalcanzable' => $hayQr && !$this->qrGenerator->urlRedirectEsAlcanzable(),
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
                    return $this->errorAlta($request, 'El archivo es requerido para el tipo "' . $tipo->value . '".');
                }
                if ($errValidacion = $this->media->validar($archivo)) {
                    return $this->errorAlta($request, $errValidacion);
                }
                $hash = $this->media->hashArchivoSubido($archivo);
                $ruta = $this->media->guardar($archivo, self::categoriaDeTipo($tipo->value));
                $contenido->setRutaArchivo($ruta);
                $contenido->setHashArchivo($hash);
            } elseif ($tipo === TipoContenido::Cronograma) {
                // El cronograma no requiere archivo ni texto — los ítems se gestionan en el builder
            } elseif ($tipo === TipoContenido::Qr) {
                // El formulario del tipo qr no manda texto: manda los datos del
                // código (destino y vencimiento) y el CMS genera el PNG acá
                // mismo. Sin esta rama el tipo caía en el 'else' de abajo y
                // exigía un 'contenido_texto' que su propio formulario no
                // muestra — crear un contenido QR fallaba siempre, con un
                // mensaje que no tenía nada que ver con la causa.
                if ($errQr = $this->aplicarDatosQr($contenido, $request)) {
                    return $this->errorAlta($request, $errQr);
                }
            } else {
                $texto = trim($request->request->get('contenido_texto', ''));
                if (!$texto) {
                    return $this->errorAlta($request, 'El campo de texto/URL es requerido para este tipo.');
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
                'html'  => $this->renderView('@SPUI/contenidos/_form.html.twig', [
                    'form' => $form,
                    // Para que al re-renderizar por un error de validación los
                    // campos del QR conserven lo que el usuario había cargado
                    // (no son campos del FormType, así que no vuelven solos).
                    'qr_url_destino' => $request->request->get('qr_url_destino'),
                    'qr_expira_en'   => $request->request->get('qr_expira_en'),
                ]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_contenidos_index');
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
                return $this->errorEdicion($request, $c, $errLimite);
            }

            $titulo = trim($request->request->get('titulo', ''));
            if (!$titulo) {
                return $this->errorEdicion($request, $c, 'El título es requerido.');
            }

            $c->setTitulo($titulo);
            $durStr = $request->request->get('duracion_segundos', '');
            $c->setDuracionSegundos($durStr !== '' ? max(1, (int) $durStr) : null);

            if (in_array($c->getTipo()->value, ['texto', 'youtube'], true)) {
                $texto = trim($request->request->get('contenido_texto', ''));
                if ($texto !== '') { $c->setContenidoTexto($texto); }
            }

            // El tipo qr no edita texto: edita su código (destino y
            // vencimiento), en el mismo formulario y con la misma naturalidad
            // que los otros tipos editan lo suyo. El texto lo escribe
            // materializar() con el destino del código, sólo como referencia
            // legible. 'qr' estaba en la lista de arriba, donde no hacía nada
            // nunca porque el formulario de este tipo no manda
            // 'contenido_texto'.
            if ($c->getTipo() === TipoContenido::Qr) {
                if ($errQr = $this->aplicarDatosQr($c, $request)) {
                    return $this->errorEdicion($request, $c, $errQr);
                }
            }

            if (in_array($c->getTipo()->value, self::TIPOS_ARCHIVO, true)) {
                $archivo = $request->files->get('archivo');
                if ($archivo) {
                    if ($errValidacion = $this->media->validar($archivo)) {
                        return $this->errorEdicion($request, $c, $errValidacion);
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
                'html'  => $this->vistaEdicion($c, null),
            ]);
        }

        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    /** Detalle del contenido (modal "Ver"). */
    /**
     * Datos para que el modal se suscriba al contador de escaneos en vivo.
     *
     * Sólo para contenidos QR: es el único lugar del CMS con un número que
     * cambia por algo que pasa afuera (un celular ajeno escaneando), y por eso
     * el único que necesita que el broker lo despierte. En cualquier otro
     * contenido devuelve null y el modal no abre ninguna conexión.
     *
     * Devuelve null también si el broker no confirmó el alta del usuario web:
     * mejor un contador que no se mueve solo que un navegador reintentando
     * conectarse contra un broker que no está.
     *
     * @return array{url:string,usuario:string,password:string,topic:string}|null
     */
    private function mqttWebParaQr(Contenido $contenido, Request $request): ?array
    {
        $qr = $contenido->getCodigoQr();
        if ($qr === null || !$this->mqttSeguridad->asegurarClienteWeb()) {
            return null;
        }

        return [
            'url'      => $this->mqttSeguridad->urlWebsocket($request),
            'usuario'  => MqttSecurityService::USUARIO_WEB,
            'password' => $this->mqttSeguridad->passwordWeb(),
            'topic'    => QrEscaneoPublisherService::topicPara($qr->getId()),
        ];
    }

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
                'html'  => $this->renderView('@SPUI/contenidos/_view.html.twig', [
                    'contenido'         => $contenido,
                    'mqtt_web'          => $this->mqttWebParaQr($contenido, $request),
                    'creado_por_email'  => $this->usuarios->email($contenido->getCreadoPorId()),
                ]),
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

        // El código QR de un contenido de este tipo le pertenece: se crea y se
        // edita desde acá y no se comparte con nadie. Se va con él, para no
        // dejar en la base un código que ya no se puede administrar desde
        // ningún lado. El findBy es por si quedaran datos viejos de cuando un
        // mismo código se podía elegir en varios contenidos.
        $codigoQr = $c->getCodigoQr();
        if ($codigoQr !== null && count($this->repo->findBy(['codigoQr' => $codigoQr])) > 1) {
            $codigoQr = null;
        }

        $this->em()->remove($c);
        if ($codigoQr !== null) {
            $this->em()->remove($codigoQr);
        }
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
