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
use SPUI\Repository\TelemetriaRepository;
use SPUI\Service\ReproductorAuthService;
use SPUI\Service\TelemetriaRollupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private readonly ReproductorAuthService $authService,
        private readonly TelemetriaRollupService $rollupService,
        private readonly ManagerRegistry $doctrine,
        #[Autowire('%env(float:default:spui_temp_alerta_default:SPUI_TEMP_ALERTA_CELSIUS)%')]
        private readonly float $tempAlertaCelsius,
        #[Autowire('%env(int:default:spui_umbral_conexion_default:SPUI_UMBRAL_CONEXION_SEG)%')]
        private readonly int $umbralConexionSeg = 150,
    ) {}

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

        $datos = [
            'reproductores'      => $reproductores,
            'telemetria'         => $telemetria,
            'alertas_activas'    => $alertasActivas,
            'conteo'             => $conteo,
            'sin_actividad'      => count($sinActividad),
            'temp_umbral'        => $this->tempAlertaCelsius,
            'sobrecalentados'    => $sobrecalentados,
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
            'series_temp'        => $this->seriesTemperatura($reproductores, $telemetria),
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
     * Temperatura promedio por hora de cada reproductor, últimas 24 h: el
     * gráfico de toda la red. Sale de la telemetría cruda (incluye la hora en
     * curso), una consulta por equipo; son pocos.
     *
     * El promedio de cada hora se dibuja al inicio de esa hora, así que la
     * hora en curso quedaba en "las 9:00" hasta las 10:00. Se le suma como
     * último punto la lectura más reciente de cada equipo (llega cada 60 s),
     * para que la línea llegue hasta ahora.
     *
     * @param Reproductor[] $reproductores
     * @param array<int, \SPUI\Entity\Telemetria|null> $ultima
     * @return list<array{nombre: string, puntos: list<array{0: string, 1: float}>}>
     */
    private function seriesTemperatura(array $reproductores, array $ultima): array
    {
        $desde = new DateTimeImmutable('-24 hours');
        $porEquipo = $this->telemetriaRepo->findTemperaturaPorHoraDeVarios($reproductores, $desde);
        $series = [];
        foreach ($reproductores as $r) {
            $puntos = $porEquipo[$r->getId()] ?? [];
            $t = $ultima[$r->getId()] ?? null;
            if ($puntos !== [] && $t !== null && $t->getRegistradoEn() > $desde) {
                $puntos[] = [$t->getRegistradoEn()->format('Y-m-d H:i:s'), $t->getTemperaturaSocCelsius()];
            }
            if ($puntos !== []) {
                $series[] = ['nombre' => $r->getHostname(), 'puntos' => $puntos];
            }
        }
        return $series;
    }
}
