<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Reproductor;
use SPUI\Form\ReproductorType;
use SPUI\Repository\ReproductorRepository;
use SPUI\Repository\TelemetriaHoraRepository;
use SPUI\Repository\TelemetriaRepository;
use SPUI\Service\AlcanceReproductorService;
use SPUI\Service\ComandoPublisherService;
use SPUI\Service\MantenimientoService;
use SPUI\Service\MqttSecurityService;
use SPUI\Service\XlsxSimpleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui/reproductores')]
class ReproductorCmsController extends AbstractController
{
    use BloqueoOfflineTrait;
    use CsrfProtegidoTrait;

    /** Rangos ofrecidos en el detalle de telemetría: etiqueta => horas hacia atrás. */
    private const RANGOS = ['24h' => 24, '7d' => 168, '30d' => 720];

    /** Cuánto dura cada pedido de telemetría en vivo; la página lo renueva a la mitad. */
    private const VIVO_SEG = 120;

    /** Formatos del modal de exportación: extensión => etiqueta. */
    private const FORMATOS_EXPORTACION = ['xlsx' => 'Excel', 'csv' => 'CSV', 'pdf' => 'PDF'];

    public function __construct(
        private readonly ReproductorRepository $repo,
        private readonly TelemetriaRepository $telemetriaRepo,
        private readonly TelemetriaHoraRepository $rollupRepo,
        private readonly AlcanceReproductorService $alcance,
        private readonly MqttSecurityService $mqttSecurity,
        private readonly ComandoPublisherService $comandos,
        private readonly ManagerRegistry $doctrine,
        #[Autowire('%env(float:default:spui_temp_alerta_default:SPUI_TEMP_ALERTA_CELSIUS)%')]
        private readonly float $tempAlertaCelsius = 80.0,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    #[Route('', name: 'spui_cms_reproductores_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/reproductores/index.html.twig', [
            'reproductores' => $this->repo->findBy([], ['hostname' => 'ASC']),
            // Se resuelve una sola vez por request: consultarlo por fila
            // dispararía la cadena completa de relaciones por cada reproductor.
            'ids_offline'   => $this->alcance->idsOfflineDeUnaVez(),
        ]);
    }

    /**
     * Histórico de telemetría de un nodo.
     *
     * Es pantalla propia y no modal porque son gráficos que uno se queda
     * mirando un rato, a diferencia del resto del ABM.
     */
    #[Route('/{id}/telemetria', name: 'spui_cms_reproductores_telemetria', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function telemetria(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) { throw $this->createNotFoundException(); }

        $rango = $request->query->getString('rango', '24h');
        if (!isset(self::RANGOS[$rango])) {
            $rango = '24h';
        }

        return $this->render('@SPUI/reproductores/telemetria.html.twig', [
            'reproductor'   => $reproductor,
            'datos'         => $this->datosTelemetria($reproductor, $rango),
            'rango'         => $rango,
            'rangos'        => array_keys(self::RANGOS),
            'temp_umbral'   => $this->tempAlertaCelsius,
            'topic_vivo'    => MqttSecurityService::TOPIC_TELEMETRIA_VIVO . $reproductor->getId(),
            'vivo_seg'      => self::VIVO_SEG,
            'export_dias'   => MantenimientoService::RETENCION_HORA_DIAS,
        ]);
    }

    /**
     * Los mismos datos que la página, para cambiar de rango sin recargarla
     * (los gráficos se redibujan con animación) y para el botón Actualizar.
     */
    #[Route('/{id}/telemetria/datos', name: 'spui_cms_reproductores_telemetria_datos', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function telemetriaDatos(int $id, Request $request): JsonResponse
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) { throw $this->createNotFoundException(); }

        $rango = $request->query->getString('rango', '24h');
        if (!isset(self::RANGOS[$rango])) {
            $rango = '24h';
        }

        return $this->json($this->datosTelemetria($reproductor, $rango));
    }

    /**
     * Pide (o renueva) el modo en vivo: la Pi publica temperatura y RAM cada
     * 1 s durante VIVO_SEG. La página lo renueva cada VIVO_SEG/2 mientras está
     * abierta, así que al cerrarla la Pi vuelve sola a publicar cada minuto.
     */
    #[Route('/{id}/telemetria/vivo', name: 'spui_cms_reproductores_telemetria_vivo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function telemetriaVivo(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) { throw $this->createNotFoundException(); }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        $ok = $this->comandos->pedirTelemetriaVivo($reproductor, self::VIVO_SEG);

        return $this->json(['success' => $ok]);
    }

    /** El modal de exportación (formato y rango), abierto desde el botón Exportar. */
    #[Route('/{id}/telemetria/exportar/form', name: 'spui_cms_reproductores_telemetria_exportar_form', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function telemetriaExportarForm(int $id): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) { throw $this->createNotFoundException(); }

        $ahora = new DateTimeImmutable();
        $hasta = $ahora->setTime((int) $ahora->format('H'), 0);
        $minimo = $ahora->modify(sprintf('-%d days', MantenimientoService::RETENCION_HORA_DIAS));

        // modal.js espera {html}, como el resto de los modales.
        return $this->json(['html' => $this->renderView('@SPUI/reproductores/_telemetria_exportar.html.twig', [
            'reproductor' => $reproductor,
            'desde'       => $hasta->modify('-24 hours')->format('Y-m-d\TH:i'),
            'hasta'       => $hasta->format('Y-m-d\TH:i'),
            'minimo'      => $minimo->setTime((int) $minimo->format('H'), 0)->format('Y-m-d\TH:i'),
            'dias'        => MantenimientoService::RETENCION_HORA_DIAS,
            'formatos'    => self::FORMATOS_EXPORTACION,
        ])]);
    }

    /**
     * Detalle por hora en Excel, CSV o PDF, entre dos fechas y horas (hasta
     * RETENCION_HORA_DIAS atrás). Los errores de las fechas vuelven como JSON
     * por campo, para mostrarlos debajo de cada uno.
     */
    #[Route('/{id}/telemetria/exportar', name: 'spui_cms_reproductores_telemetria_exportar', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function telemetriaExportar(int $id, Request $request, XlsxSimpleService $xlsx): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) { throw $this->createNotFoundException(); }

        $formato = $request->query->getString('formato', 'xlsx');
        if (!isset(self::FORMATOS_EXPORTACION[$formato])) {
            return $this->json(['success' => false, 'errores' => ['formato' => 'Elegí un formato.']], 422);
        }

        $leer = static function (string $valor): ?DateTimeImmutable {
            $f = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $valor);
            return $f !== false ? $f : null;
        };
        $desde = $leer($request->query->getString('desde'));
        $hasta = $leer($request->query->getString('hasta'));
        $limite = new DateTimeImmutable(sprintf('-%d days', MantenimientoService::RETENCION_HORA_DIAS));

        $errores = [];
        if ($desde === null) {
            $errores['desde'] = 'Elegí la fecha y la hora de inicio.';
        } elseif ($desde < $limite->setTime((int) $limite->format('H'), 0)) {
            $errores['desde'] = sprintf('Se conservan los últimos %d días: elegí una fecha desde el %s.', MantenimientoService::RETENCION_HORA_DIAS, $limite->format('d/m/Y'));
        }
        if ($hasta === null) {
            $errores['hasta'] = 'Elegí la fecha y la hora de fin.';
        } elseif ($desde !== null && $hasta <= $desde) {
            $errores['hasta'] = 'Tiene que ser posterior al inicio.';
        }
        if ($errores) {
            return $this->json(['success' => false, 'errores' => $errores], 422);
        }

        $horas = $this->rollupRepo->findEntre($reproductor, $desde, $hasta);
        if ($horas === []) {
            return $this->json(['success' => false, 'mensaje' => 'No hay datos de este reproductor en ese período. Probá con otras fechas.'], 422);
        }

        // El disco se informa EN USO contra la capacidad (lo que importa es
        // cuánto falta para llenarlo). Por hora, el máximo en uso es la
        // capacidad menos el mínimo libre. Sin capacidad conocida (cliente de
        // la Pi anterior al 29/10/2026) se informa lo libre, aclarado.
        $totalMb = $reproductor->getDiscoTotalMb();
        $columnas = [
            ['titulo' => 'Hora',                'tipo' => 'fecha',   'ancho' => 18],
            ['titulo' => 'Temp. promedio (°C)', 'tipo' => 'decimal', 'ancho' => 20],
            ['titulo' => 'Temp. máxima (°C)',   'tipo' => 'decimal', 'ancho' => 19],
            ['titulo' => 'RAM promedio (%)',    'tipo' => 'decimal', 'ancho' => 18],
            ['titulo' => 'RAM máxima (%)',      'tipo' => 'decimal', 'ancho' => 16],
        ];
        $columnas = array_merge($columnas, $totalMb !== null ? [
            ['titulo' => 'Disco en uso máx. (GB)', 'tipo' => 'decimal', 'ancho' => 22],
            ['titulo' => 'Disco en uso máx. (%)',  'tipo' => 'decimal', 'ancho' => 21],
            ['titulo' => 'Capacidad (GB)',         'tipo' => 'decimal', 'ancho' => 15],
        ] : [
            ['titulo' => 'Disco libre mín. (GB)', 'tipo' => 'decimal', 'ancho' => 21],
        ], [
            ['titulo' => 'Lecturas', 'tipo' => 'entero', 'ancho' => 10],
        ]);
        $filas = array_map(static function (array $f) use ($totalMb) {
            $disco = $totalMb !== null
                ? [round(max(0, $totalMb - $f['disco_min']) / 1024, 1), round(max(0, $totalMb - $f['disco_min']) / $totalMb * 100, 1), round($totalMb / 1024, 1)]
                : [round($f['disco_min'] / 1024, 1)];
            return array_merge([$f['hora'], $f['temp_prom'], $f['temp_max'], $f['ram_prom'], $f['ram_max']], $disco, [$f['lecturas']]);
        }, $horas);

        [$binario, $tipoMime] = match ($formato) {
            'xlsx' => [$xlsx->generar('Telemetría', $columnas, $filas), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'csv'  => [$this->csv($columnas, $filas), 'text/csv; charset=UTF-8'],
            'pdf'  => [$this->pdf($reproductor, $desde, $hasta, $horas), 'application/pdf'],
        };

        // Sólo ASCII: así el mismo nombre sirve de filename y no hace falta un
        // respaldo genérico (que es el que terminaba usando el navegador).
        $host = preg_replace('/[^A-Za-z0-9._-]+/', '-', $reproductor->getHostname());
        $nombre = sprintf('telemetria-%s-%s-a-%s.%s', $host, $desde->format('Ymd-Hi'), $hasta->format('Ymd-Hi'), $formato);
        $respuesta = new Response($binario);
        $respuesta->headers->set('Content-Type', $tipoMime);
        $respuesta->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nombre));

        return $respuesta;
    }

    /**
     * CSV pensado para abrirse con doble clic en un Excel en castellano: punto
     * y coma como separador, coma decimal y BOM para que reconozca el UTF-8
     * (sin BOM, "°C" y las tildes salen rotas).
     */
    private function csv(array $columnas, array $filas): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_column($columnas, 'titulo'), ';', '"', '');
        foreach ($filas as $fila) {
            fputcsv($out, array_map(static function ($v, array $c) {
                return match ($c['tipo']) {
                    'fecha'   => $v->format('d/m/Y H:i'),
                    'decimal' => $v === null ? '' : str_replace('.', ',', (string) $v),
                    default   => (string) $v,
                };
            }, $fila, $columnas), ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /** Un informe para imprimir o adjuntar: datos del equipo, resumen del período y el detalle por hora. */
    private function pdf(Reproductor $reproductor, DateTimeImmutable $desde, DateTimeImmutable $hasta, array $horas): string
    {
        $html = $this->renderView('@SPUI/reproductores/telemetria_pdf.html.twig', [
            'reproductor' => $reproductor,
            'desde'       => $desde,
            'hasta'       => $hasta,
            'horas'       => $horas,
            'resumen'     => $this->rollupRepo->resumen($reproductor, $desde, $hasta),
            'total_mb'    => $reproductor->getDiscoTotalMb(),
            'temp_umbral' => $this->tempAlertaCelsius,
            'generado'    => new DateTimeImmutable(),
        ]);

        $opciones = new Options();
        $opciones->set('defaultFont', 'DejaVu Sans');    // trae °, ñ y tildes
        $opciones->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Todo lo que usan los gráficos: la serie por hora de los últimos
     * RETENCION_HORA_DIAS días, el resumen de cada rango y la última lectura.
     *
     * La serie es siempre la de 60 días y los botones 24 h / 7 d / 30 d sólo
     * eligen qué ventana se ve: así se puede arrastrar el gráfico hacia atrás
     * y ver las horas anteriores sin pedir nada más (29/10/2026).
     *
     * @return array{rango:string, serie: list<array<string,mixed>>, resumenes: array<string,?array<string,mixed>>, ultima: ?array<string,mixed>}
     */
    private function datosTelemetria(Reproductor $reproductor, string $rango): array
    {
        // Las horas cerradas salen del rollup; las que el rollup todavía no
        // agregó (la en curso y, como mucho, la anterior) se calculan de la
        // telemetría cruda, que tiene el mismo shape. Si no hay rollup todavía
        // (un reproductor nuevo), todo sale del crudo, que se guarda 7 días.
        $serie = $this->rollupRepo->findSerie($reproductor, new DateTimeImmutable(sprintf('-%d days', MantenimientoService::RETENCION_HORA_DIAS)));
        $ultimoPeriodo = $serie === [] ? null : end($serie)['periodo'];
        $desdeCrudo = $ultimoPeriodo !== null
            ? (new DateTimeImmutable($ultimoPeriodo))->modify('+1 hour')
            : new DateTimeImmutable(sprintf('-%d days', MantenimientoService::RETENCION_DIAS_DEFAULT));
        foreach ($this->telemetriaRepo->findSeriePorHora($reproductor, $desdeCrudo) as $p) {
            if ($ultimoPeriodo === null || $p['periodo'] > $ultimoPeriodo) {
                $serie[] = $p;
            }
        }

        // El resumen de las últimas 24 h sale del crudo (incluye la hora en
        // curso); los de 7 y 30 días, del rollup ponderado por lecturas.
        $resumenes = [];
        foreach (self::RANGOS as $clave => $horas) {
            $desde = new DateTimeImmutable(sprintf('-%d hours', $horas));
            $resumenes[$clave] = $horas > self::RANGOS['24h']
                ? $this->rollupRepo->resumen($reproductor, $desde)
                : $this->telemetriaRepo->resumen($reproductor, $desde);
        }

        $ultima = $this->telemetriaRepo->findUltimos($reproductor, 1)[0] ?? null;

        return [
            'rango'     => $rango,
            'serie'     => $serie,
            'resumenes' => $resumenes,
            'ultima'    => $ultima === null ? null : [
                'temp'           => round($ultima->getTemperaturaSocCelsius(), 1),
                'ram'            => round($ultima->getUsoRamPorcentaje(), 1),
                'disco_gb'       => round($ultima->getEspacioDiscoLibreMb() / 1024, 1),
                'disco_total_gb' => $reproductor->getDiscoTotalMb() !== null ? round($reproductor->getDiscoTotalMb() / 1024, 1) : null,
                'cuando'         => $ultima->getRegistradoEn()->format('d/m/Y H:i:s'),
            ],
        ];
    }

    #[Route('/{id}/ver', name: 'spui_cms_reproductores_ver', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ver(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) { throw $this->createNotFoundException(); }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => $reproductor->getHostname(),
                'html'  => $this->renderView('@SPUI/reproductores/_view.html.twig', ['reproductor' => $reproductor]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_reproductores_index');
    }

    #[Route('/nuevo', name: 'spui_cms_reproductores_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        $reproductor = new Reproductor();
        $form = $this->createForm(ReproductorType::class, $reproductor, [
            'action' => $this->generateUrl('spui_cms_reproductores_nuevo'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $rawKey = bin2hex(random_bytes(32));
            $reproductor->setApiKeyHash(hash('sha256', $rawKey));
            $this->em()->persist($reproductor);
            $this->em()->flush();

            // El flush va antes: si el broker no responde, el reproductor ya
            // quedó registrado y funcionando por HTTP. Lo único que falta es su
            // credencial MQTT, que se recrea volviendo a regenerar la clave.
            $mqttOk = $this->mqttSecurity->sincronizar($reproductor, $rawKey);

            // La clave se muestra una única vez, en el modal (showApiKeyModal).
            return $this->json([
                'success'  => true,
                'apiKey'   => $rawKey,
                'hostname' => $reproductor->getHostname(),
                'mqttOk'   => $mqttOk,
                'message'  => 'Reproductor "' . $reproductor->getHostname() . '" registrado.'
                    . ($mqttOk ? '' : ' Atención: no se pudo crear su credencial en el broker MQTT'
                        . ' (¿Mosquitto corriendo?). El equipo va a funcionar, pero sin alertas'
                        . ' instantáneas hasta que regeneres la clave con el broker disponible.'),
            ]);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nuevo reproductor',
                'html'  => $this->renderView('@SPUI/reproductores/_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_reproductores_index');
    }

    #[Route('/{id}/editar', name: 'spui_cms_reproductores_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) {
            throw $this->createNotFoundException();
        }

        $intervaloAntes = $reproductor->getIntervaloSyncMin();
        $form = $this->createForm(ReproductorType::class, $reproductor, [
            'action' => $this->generateUrl('spui_cms_reproductores_editar', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($r = $this->bloquearSiOffline([$reproductor], $request, 'spui_cms_reproductores_index')) {
                return $r;
            }

            $this->em()->flush();
            // Si cambió el intervalo, la Pi lo toma en su próximo sync: se le
            // pide ya, porque con el intervalo viejo (que pudo ser de horas)
            // seguiría esperando sin enterarse.
            if ($reproductor->getIntervaloSyncMin() !== $intervaloAntes) {
                $this->comandos->pedirSyncAhora([$reproductor], 'intervalo_sync');
            }
            $msg = 'Reproductor "' . $reproductor->getHostname() . '" actualizado.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_reproductores_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Editar: ' . $reproductor->getHostname(),
                'html'  => $this->renderView('@SPUI/reproductores/_form.html.twig', ['form' => $form, 'reproductor' => $reproductor]),
            ]);
        }

        return $this->redirectToRoute('spui_cms_reproductores_index');
    }

    /**
     * "Sincronizar ahora": le pide al reproductor que consulte al CMS ya mismo,
     * sin esperar su intervalo. Es el mismo aviso sync_ahora por MQTT que se
     * manda solo al guardar cualquier cambio que afecte a sus pantallas.
     */
    #[Route('/{id}/sincronizar', name: 'spui_cms_reproductores_sincronizar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function sincronizar(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }
        // Apagado o sin red no puede recibir el aviso: igual que cualquier
        // otra acción sobre un equipo desconectado.
        if ($r = $this->bloquearSiOffline([$reproductor], $request, 'spui_cms_reproductores_index')) {
            return $r;
        }

        $host = $reproductor->getHostname();

        // El CMS acepta hasta 4 syncs por minuto por reproductor: si acaba de
        // sincronizar, el pedido terminaría rechazado del lado de la Pi.
        $hace = $reproductor->getUltimoSync() !== null ? time() - $reproductor->getUltimoSync()->getTimestamp() : null;
        if ($hace !== null && $hace < self::SYNC_MANUAL_ESPERA_SEG) {
            return $this->respuestaAccion($request, false,
                '«' . $host . '» se sincronizó hace ' . max(1, $hace) . ' s. Esperá '
                . (self::SYNC_MANUAL_ESPERA_SEG - $hace) . ' s para volver a pedirlo.', 'warning');
        }

        if ($this->comandos->pedirSyncAhora([$reproductor], 'manual') === 0) {
            $proximo = $reproductor->proximoSyncEstimado();
            return $this->respuestaAccion($request, false,
                'No se pudo avisar a «' . $host . '» en este momento: el servidor de mensajería (MQTT) no respondió. '
                . ($proximo !== null ? 'Va a sincronizar solo cerca de las ' . $proximo->format('H:i') . '.' : 'Va a sincronizar solo en su próximo ciclo.'),
                'warning');
        }

        return $this->respuestaAccion($request, true,
            'Pedido enviado. «' . $host . '» se sincroniza en unos segundos; el último sync se actualiza solo en el listado.');
    }

    /** Un sync manual, como mucho cada 30 s: deja lugar en el límite de 4 por minuto para el periódico y los avisos. */
    private const SYNC_MANUAL_ESPERA_SEG = 30;

    private function respuestaAccion(Request $request, bool $ok, string $mensaje, ?string $tipo = null): Response
    {
        if ($request->isXmlHttpRequest()) {
            // success:true + type:warning: no es un error del operador, es un aviso.
            $datos = ['success' => $ok || $tipo === 'warning', 'message' => $mensaje];
            if ($tipo !== null) {
                $datos['type'] = $tipo;
            }
            return $this->json($datos);
        }
        $this->addFlash($tipo ?? ($ok ? 'success' : 'error'), $mensaje);
        return $this->redirectToRoute('spui_cms_reproductores_index');
    }

    #[Route('/{id}/regenerar-clave', name: 'spui_cms_reproductores_regenerar_clave', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function regenerarClave(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        // De todas las acciones del CMS, ésta es la que peor tolera que el
        // equipo esté offline: la clave nueva hay que copiarla a mano al
        // spui.env de la Raspberry, y hasta que alguien lo haga físicamente el
        // reproductor queda sin poder autenticarse.
        if ($r = $this->bloquearSiOffline([$reproductor], $request, 'spui_cms_reproductores_index')) {
            return $r;
        }

        // Se guarda ANTES de pisarla: tarea 1.4, para poder distinguir en el
        // dashboard un intento con esta clave vieja (la Pi real, con el .env
        // sin actualizar) de un intento genuinamente desconocido.
        $reproductor->marcarHashAnterior($reproductor->getApiKeyHash());

        $rawKey = bin2hex(random_bytes(32));
        $reproductor->setApiKeyHash(hash('sha256', $rawKey));
        $this->em()->flush();

        // Obligatorio acá: la contraseña MQTT se deriva de la API key, así que
        // regenerarla sin sincronizar el broker dejaría al equipo autenticando
        // bien por HTTP pero rechazado en MQTT — se quedaría sin alertas sin
        // ningún error visible en el CMS.
        $mqttOk = $this->mqttSecurity->sincronizar($reproductor, $rawKey);

        // La clave nueva se muestra una única vez, en el modal (showApiKeyModal).
        return $this->json([
            'success'  => true,
            'apiKey'   => $rawKey,
            'hostname' => $reproductor->getHostname(),
            'mqttOk'   => $mqttOk,
            'message'  => 'Clave API regenerada para "' . $reproductor->getHostname() . '".'
                . ' Acordate de actualizar SPUI_API_KEY en el spui.env de la Raspberry'
                . ' y reiniciar el servicio, o el equipo va a quedar sin comunicación.'
                . ($mqttOk ? '' : ' Atención: no se pudo actualizar su credencial en el broker'
                    . ' MQTT (¿Mosquitto corriendo?). Volvé a regenerar la clave cuando esté'
                    . ' disponible.'),
        ]);
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_reproductores_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $reproductor = $this->repo->find($id);
        if (!$reproductor) {
            throw $this->createNotFoundException();
        }
        if ($r = $this->denegarSiCsrfInvalido($request)) { return $r; }

        if (!$reproductor->getPantallas()->isEmpty()) {
            $msg = 'No se puede eliminar: tiene ' . $reproductor->getPantallas()->count() . ' pantalla(s) asignada(s). Desasignálas primero desde cada pantalla.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $msg], 422);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_reproductores_index');
        }

        // Excepción deliberada a la regla de bloquear todo lo que toque un
        // reproductor desconectado: dar de baja un equipo es justamente lo que
        // se hace CUANDO se rompió y no va a volver. Bloquearlo por estar
        // offline dejaba el hardware muerto imposible de sacar del sistema sin
        // tocar la base a mano. No hay riesgo de descoordinación: no cambia
        // nada de lo que una pantalla activa esté mostrando — la fila se va del
        // CMS y la credencial MQTT se revoca acá abajo.
        //
        // Revocar antes de borrar la fila: después ya no se puede derivar el
        // usuario MQTT, y la credencial quedaría válida en el broker para
        // siempre — un equipo dado de baja podría seguir conectándose.
        //
        // El resultado NO se descarta: si el broker está caído, la revocación
        // falla y la credencial queda huérfana sin posibilidad de limpiarla
        // después (el usuario se deriva del id, que está por desaparecer). Hay
        // que avisarlo, igual que hacen crear y regenerar-clave.
        $usuarioMqtt = $reproductor->mqttUsuario();
        $mqttOk      = $this->mqttSecurity->revocar($reproductor);

        $hostname = $reproductor->getHostname();
        $this->em()->remove($reproductor);
        $this->em()->flush();

        $msg = 'Reproductor "' . $hostname . '" eliminado.';
        if (!$mqttOk) {
            $msg .= ' Atención: no se pudo revocar su credencial en el broker MQTT'
                . ' (¿Mosquitto corriendo?). El usuario "' . $usuarioMqtt . '" sigue'
                . ' habilitado y hay que borrarlo a mano con:'
                . ' mosquitto_ctrl -u spui-admin -h 127.0.0.1 dynsec deleteClient ' . $usuarioMqtt;
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => true,
                'mqttOk'  => $mqttOk,
                'type'    => $mqttOk ? 'success' : 'warning',
                'message' => $msg,
            ]);
        }
        $this->addFlash($mqttOk ? 'success' : 'warning', $msg);
        return $this->redirectToRoute('spui_cms_reproductores_index');
    }
}
