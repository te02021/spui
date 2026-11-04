<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\AlertaEmergencia;
use SPUI\Entity\Contenido;
use SPUI\Entity\Pantalla;
use SPUI\Entity\Playlist;
use SPUI\Entity\Programacion;
use SPUI\Entity\Reproductor;
use SPUI\Enum\EstadoConexion;
use SPUI\Enum\EstadoContenido;
use SPUI\Enum\TipoContenido;
use SPUI\Repository\AlertaEmergenciaRepository;
use SPUI\Repository\ReproductorRepository;
use SPUI\Repository\TelemetriaHoraRepository;
use SPUI\Repository\TelemetriaRepository;
use SPUI\Service\ReproductorAuthService;
use SPUI\Service\TelemetriaRollupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spui')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ReproductorRepository $reproductorRepo,
        private readonly AlertaEmergenciaRepository $alertaRepo,
        private readonly TelemetriaRepository $telemetriaRepo,
        private readonly TelemetriaHoraRepository $telemetriaHoraRepo,
        private readonly ReproductorAuthService $authService,
        private readonly TelemetriaRollupService $rollupService,
        private readonly ManagerRegistry $doctrine,
        #[Autowire('%env(float:default:spui_temp_alerta_default:SPUI_TEMP_ALERTA_CELSIUS)%')]
        private readonly float $tempAlertaCelsius,
        #[Autowire('%env(float:default:spui_cpu_alerta_default:SPUI_CPU_ALERTA_PORCENTAJE)%')]
        private readonly float $cpuAlertaPorcentaje,
        #[Autowire('%env(int:default:spui_umbral_conexion_default:SPUI_UMBRAL_CONEXION_SEG)%')]
        private readonly int $umbralConexionSeg = 150,
    ) {}

    /** Lo que se puede mirar en el gráfico único del Dashboard (03/11/2026). */
    public const VARIABLES = ['temp', 'cpu', 'ram', 'disco', 'latencia'];
    public const PERIODOS = ['24h' => 24, '7d' => 168, '30d' => 720];

    #[Route('', name: 'spui_cms_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $reproductores = $this->reproductorRepo->findBy([], ['id' => 'ASC']);

        // Última telemetría de todos los nodos en una sola consulta (antes N+1).
        // Se arranca con null en todos: un nodo que nunca reportó no aparece en
        // el resultado, y la plantilla accede a telemetria[id] sin preguntar.
        $telemetria = array_fill_keys(
            array_map(fn($r) => $r->getId(), $reproductores),
            null,
        );
        $telemetria = $this->telemetriaRepo->findUltimaPorReproductor($reproductores) + $telemetria;

        $alertasActivas = $this->alertaRepo->findBy(['activa' => true], ['prioridad' => 'DESC']);

        // El conteo sale del estado CALCULADO, no de la columna: así el panel
        // dice la verdad aunque spui:mantenimiento no haya corrido todavía.
        // Antes, con el cron sin agendar, un equipo apagado se contaba como
        // conectado indefinidamente.
        $conteo = ['conectado' => 0, 'desconectado' => 0, 'sin_registrar' => 0];
        foreach ($reproductores as $r) {
            $estado = $r->estadoCalculado($this->umbralConexionSeg)->value;
            $conteo[$estado] = ($conteo[$estado] ?? 0) + 1;
        }

        // Reproductores que alguna vez reportaron y ahora están vencidos. Es el
        // mismo criterio que 'desconectado', separado porque la tarjeta lo
        // muestra aparte para distinguirlo de los que nunca se registraron.
        $sinActividad = array_filter(
            $reproductores,
            fn($r) => $r->estadoCalculado($this->umbralConexionSeg) === EstadoConexion::Desconectado,
        );

        // Nodos con temperatura sobre el umbral: es la "alerta visual" que pide CU-08.
        $sobrecalentados = [];
        foreach ($telemetria as $repId => $t) {
            if ($t !== null && $t->getTemperaturaSocCelsius() >= $this->tempAlertaCelsius) {
                $sobrecalentados[$repId] = $t->getTemperaturaSocCelsius();
            }
        }

        // CPU alta sostenida (03/11/2026): el promedio de los últimos 10
        // minutos, no una lectura suelta (abrir un video la sube un instante).
        $cpuAltos = $this->telemetriaRepo->findCpuAlta($reproductores, new DateTimeImmutable('-10 minutes'), $this->cpuAlertaPorcentaje);

        $datos = [
            'reproductores'      => $reproductores,
            'telemetria'         => $telemetria,
            'alertas_activas'    => $alertasActivas,
            'conteo'             => $conteo,
            'sin_actividad'      => count($sinActividad),
            'temp_umbral'        => $this->tempAlertaCelsius,
            'sobrecalentados'    => $sobrecalentados,
            'cpu_altos'          => $cpuAltos,
            'cpu_umbral'         => $this->cpuAlertaPorcentaje,
            // Tarea 1.4: intentos de auth con API key inválida en la última
            // hora, para que un problema de credenciales se vea en el panel
            // en vez de descubrirse por SSH horas después.
            'intentos_fallidos'  => $intentosFallidos = $this->authService->intentosFallidosUltimaHora(),
            'hostnames_clave_vieja' => array_values(array_unique(array_filter(
                array_map(fn(array $i) => $i['reproductor_clave_vieja'], $intentosFallidos),
            ))),
            // Red de seguridad: si el scheduler interno del demonio
            // (mantenimiento/rollup, tareas 1.5/1.7) se cayó, que se vea acá
            // en vez de descubrirse semanas después con la tabla de
            // telemetría desbordada.
            'scheduler_desactualizado' => $this->rollupService->estaDesactualizado(),
            // Lavada de cara del 29/10/2026: el resto de la red de un vistazo
            // y la temperatura de cada equipo en las últimas 24 h.
            'resumen_red'        => $this->resumenRed(),
            // El gráfico único arranca en la temperatura de las últimas 24 h;
            // otra variable u otro período se piden a /dashboard/serie.
            'grafico'            => $this->serie('temp', '24h', $reproductores, $telemetria),
        ];

        // El refresco automático pide esta misma ruta cada 20 s y sólo usa el
        // contenido de #dashboard-zona. Devolver el layout completo —sidebar,
        // footer y los dos modales— sería mandar varias veces el peso útil en
        // cada ciclo, para descartarlo enseguida del lado del navegador.
        if ($request->isXmlHttpRequest()) {
            return $this->render('@SPUI/dashboard/_contenido.html.twig', $datos);
        }

        return $this->render('@SPUI/dashboard/index.html.twig', $datos);
    }

    /**
     * Lo que se carga en el CMS, para las cuatro tarjetas del Dashboard. Cada
     * una responde una pregunta de operación (rehecho el 30/10/2026):
     * cuántas pantallas están mostrando ahora, con qué contenido, si la
     * programación cubre a todas, y si hay alguna alerta en curso.
     *
     * @return array<string, mixed>
     */
    private function resumenRed(): array
    {
        $em = $this->doctrine->getManager('SPUI');
        $pantallas = $em->getRepository(Pantalla::class)->findAll();
        $activas = array_filter($pantallas, static fn(Pantalla $p) => $p->isActiva());

        // "En línea": activa y con su reproductor conectado según el mismo
        // estado calculado que usa el resto del panel.
        $enLinea = array_filter($activas, fn(Pantalla $p) => $p->getReproductor() !== null
            && $p->getReproductor()->estadoCalculado($this->umbralConexionSeg) === EstadoConexion::Conectado);

        $porTipo = [];
        foreach (TipoContenido::cases() as $tipo) {
            $porTipo[] = [
                'tipo'     => $tipo->value,
                'etiqueta' => $tipo->etiqueta(),
                'cantidad' => $em->getRepository(Contenido::class)->count(['tipo' => $tipo, 'estado' => EstadoContenido::Disponible]),
            ];
        }

        $ultimaAlerta = $em->getRepository(AlertaEmergencia::class)->createQueryBuilder('a')
            ->where('a.activadaEn IS NOT NULL')->orderBy('a.activadaEn', 'DESC')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return [
            'pantallas'          => count($pantallas),
            'pantallas_activas'  => count($activas),
            'pantallas_en_linea' => count($enLinea),
            'edificios'          => count(array_unique(array_map(static fn(Pantalla $p) => $p->getUbicacion()->getEdificio()->getId(), $pantallas))),
            'sin_respaldo'       => count(array_filter($activas, static fn(Pantalla $p) => $p->getPlaylistFallback() === null)),
            'contenidos'         => array_sum(array_column($porTipo, 'cantidad')),
            'contenidos_tipo'    => $porTipo,
            'archivados'         => $em->getRepository(Contenido::class)->count(['estado' => EstadoContenido::Archivado]),
            'playlists'          => $em->getRepository(Playlist::class)->count([]),
            'reglas'             => $em->getRepository(Programacion::class)->count([]),
            'reglas_activas'     => $em->getRepository(Programacion::class)->count(['activo' => true]),
            'alertas'            => $em->getRepository(AlertaEmergencia::class)->count([]),
            'ultima_alerta'      => $ultimaAlerta,
        ];
    }

    /**
     * Los datos del gráfico único (03/11/2026) para otra variable u otro
     * período, sin recargar la página. Los reproductores que se ven los elige
     * el navegador: acá vienen todos.
     */
    #[Route('/dashboard/serie', name: 'spui_cms_dashboard_serie', methods: ['GET'])]
    public function serieJson(Request $request): JsonResponse
    {
        $variable = $request->query->getString('variable', 'temp');
        $periodo  = $request->query->getString('periodo', '24h');
        if (!in_array($variable, self::VARIABLES, true) || !isset(self::PERIODOS[$periodo])) {
            return $this->json(['error' => 'Variable o período desconocido.'], 400);
        }
        $reproductores = $this->reproductorRepo->findBy([], ['id' => 'ASC']);
        $ultima = $this->telemetriaRepo->findUltimaPorReproductor($reproductores);

        return $this->json($this->serie($variable, $periodo, $reproductores, $ultima));
    }

    /**
     * Una variable de todos los reproductores, por hora, en el período: una
     * serie por equipo, con su última lectura al final para que la línea
     * llegue hasta ahora.
     *
     * 24 h y 7 días salen de la telemetría cruda (se guarda 7 días); 30 días,
     * de los resúmenes por hora, más el crudo de las horas que el resumen
     * todavía no agregó.
     *
     * El disco se muestra como % en uso contra la capacidad de cada equipo;
     * un equipo que no la informa (cliente anterior al 29/10/2026) no aparece.
     *
     * @param Reproductor[] $reproductores
     * @param array<int, \SPUI\Entity\Telemetria|null> $ultima
     * @return array{variable: string, periodo: string, umbral: ?float, series: list<array{id: int, nombre: string, puntos: list<array{0: string, 1: float}>}>}
     */
    private function serie(string $variable, string $periodo, array $reproductores, array $ultima): array
    {
        $desde = new DateTimeImmutable(sprintf('-%d hours', self::PERIODOS[$periodo]));
        if ($periodo === '30d') {
            $hasta = $this->telemetriaHoraRepo->ultimaHoraAgregada();
            $porEquipo = $hasta !== null ? $this->telemetriaHoraRepo->findPorHoraDeVarios($reproductores, $desde, $hasta, $variable) : [];
            $desdeCrudo = $hasta !== null ? $hasta->modify('+1 hour') : new DateTimeImmutable('-7 days');
            foreach ($this->telemetriaRepo->findPorHoraDeVarios($reproductores, $desdeCrudo, $variable) as $id => $puntos) {
                $porEquipo[$id] = array_merge($porEquipo[$id] ?? [], $puntos);
            }
        } else {
            $porEquipo = $this->telemetriaRepo->findPorHoraDeVarios($reproductores, $desde, $variable);
        }

        $series = [];
        foreach ($reproductores as $r) {
            $totalMb = $r->getDiscoTotalMb();
            if ($variable === 'disco' && !$totalMb) {
                continue;
            }
            $aValor = static fn(float $v): float => $variable === 'disco'
                ? round(max(0, $totalMb - $v) / $totalMb * 100, 1)
                : $v;
            $puntos = array_map(static fn(array $p) => [$p[0], $aValor($p[1])], $porEquipo[$r->getId()] ?? []);

            $t = $ultima[$r->getId()] ?? null;
            $ultimoValor = $t === null ? null : match ($variable) {
                'temp'     => $t->getTemperaturaSocCelsius(),
                'cpu'      => $t->getUsoCpuPorcentaje(),
                'ram'      => $t->getUsoRamPorcentaje(),
                'disco'    => (float) $t->getEspacioDiscoLibreMb(),
                'latencia' => $t->getLatenciaRedMs() !== null ? (float) $t->getLatenciaRedMs() : null,
            };
            if ($puntos !== [] && $ultimoValor !== null && $t->getRegistradoEn() > $desde) {
                $puntos[] = [$t->getRegistradoEn()->format('Y-m-d H:i:s'), round($aValor($ultimoValor), 1)];
            }
            if ($puntos !== []) {
                $series[] = ['id' => $r->getId(), 'nombre' => $r->getHostname(), 'puntos' => $puntos];
            }
        }

        return [
            'variable' => $variable,
            'periodo'  => $periodo,
            'umbral'   => match ($variable) {
                'temp'  => $this->tempAlertaCelsius,
                'cpu'   => $this->cpuAlertaPorcentaje,
                default => null,
            },
            'series'   => $series,
        ];
    }
}
