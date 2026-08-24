<?php

declare(strict_types=1);

namespace SPUI\Command;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Enum\EstadoConexion;
use SPUI\Repository\AlertaEmergenciaRepository;
use SPUI\Repository\ReproductorRepository;
use SPUI\Repository\TelemetriaRepository;
use SPUI\Service\AlertaPublisherService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tareas periódicas de mantenimiento de la red de reproductores.
 *
 * 1. Marca como 'desconectado' los reproductores cuyo heartbeat venció.
 *    Sin esto un nodo apagado queda 'conectado' para siempre en la BD, porque
 *    Reproductor::registrarHeartbeat() es el único punto que escribe el estado
 *    y sólo sabe ponerlo en 'conectado'.
 *
 * 2. Purga la telemetría vieja según la política de retención del proyecto.
 *
 * Uso (el monolito exige --id para resolver la app):
 *   php bin/console spui:mantenimiento --id=spui
 *   php bin/console spui:mantenimiento --id=spui --minutos=10 --retencion-dias=90
 *   php bin/console spui:mantenimiento --id=spui --dry-run
 *
 * Pensado para correr cada minuto vía cron / Programador de tareas de Windows.
 */
#[AsCommand(
    name: 'spui:mantenimiento',
    description: 'Marca reproductores sin heartbeat como desconectados y purga telemetría vieja.',
)]
class MantenimientoCommand extends Command
{
    // El umbral de desconexión ya no vive acá: es spui_umbral_conexion_default
    // en services.yaml, inyectado abajo. Estaba repetido en este comando, en el
    // dashboard y en dos templates, y cambiarlo en uno solo dejaba el panel
    // contradiciéndose.

    /**
     * Días de telemetría CRUDA a conservar.
     *
     * Bajó de 90 a 7 al incorporarse telemetria_hora: el histórico largo vive
     * en el rollup, que ocupa 1/60 y es lo que los gráficos consumen para los
     * rangos de 7 y 30 días. El crudo sólo hace falta para el detalle de las
     * últimas 24 h, así que 7 días deja margen de sobra.
     *
     * IMPORTANTE: spui:telemetria:rollup tiene que correr ANTES que este
     * comando. Si la purga se adelanta, borra lecturas que aún no fueron
     * agregadas y esas horas se pierden del histórico.
     */
    private const RETENCION_DIAS_DEFAULT = 7;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ReproductorRepository $reproductorRepo,
        private readonly TelemetriaRepository $telemetriaRepo,
        private readonly AlertaEmergenciaRepository $alertaRepo,
        private readonly AlertaPublisherService $alertaPublisher,
        #[Autowire('%env(int:default:spui_umbral_conexion_default:SPUI_UMBRAL_CONEXION_SEG)%')]
        private readonly int $umbralConexionSeg = 150,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'segundos',
                null,
                InputOption::VALUE_REQUIRED,
                'Segundos sin heartbeat para marcar un reproductor como desconectado (default: el de services.yaml)',
            )
            ->addOption(
                'retencion-dias',
                null,
                InputOption::VALUE_REQUIRED,
                'Días de telemetría a conservar (0 = no purgar)',
                (string) self::RETENCION_DIAS_DEFAULT,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Muestra lo que haría sin escribir en la base de datos',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $segundos = max(30, (int) ($input->getOption('segundos') ?: $this->umbralConexionSeg));
        $dias     = max(0, (int) $input->getOption('retencion-dias'));
        $dryRun   = (bool) $input->getOption('dry-run');

        $io->title('SPUI — Mantenimiento');
        if ($dryRun) {
            $io->note('Modo dry-run: no se escribe nada en la base de datos.');
        }

        $em = $this->doctrine->getManager('SPUI');

        // ── 1. Reproductores caídos ──────────────────────────────────────────
        //
        // Desde que el panel calcula el estado con Reproductor::estadoCalculado(),
        // esto ya no es lo que hace que un equipo caído se vea como tal: la
        // pantalla dice la verdad aunque este comando no corra nunca. Se
        // mantiene para que la columna estado_conexion quede coherente con lo
        // que se muestra, porque la lee la API REST y cualquier consulta SQL
        // directa.
        $limite  = new DateTimeImmutable(sprintf('-%d seconds', $segundos));
        $caidos  = $this->reproductorRepo->findConectadosSinHeartbeatDesde($limite);

        if ($caidos === []) {
            $io->text(sprintf('Reproductores: ninguno superó los %d s sin heartbeat.', $segundos));
        } else {
            foreach ($caidos as $reproductor) {
                $io->text(sprintf(
                    '  <comment>%s</comment> (id=%d) — último heartbeat: %s',
                    $reproductor->getHostname(),
                    $reproductor->getId(),
                    $reproductor->getUltimoHeartbeat()?->format('Y-m-d H:i:s') ?? 'nunca',
                ));
                if (!$dryRun) {
                    $reproductor->setEstadoConexion(EstadoConexion::Desconectado);
                }
            }
            if (!$dryRun) {
                $em->flush();
            }
            $io->warning(sprintf(
                '%d reproductor(es) marcado(s) como desconectado(s) tras %d s sin heartbeat.',
                count($caidos),
                $segundos,
            ));
        }

        // ── 2. Purga de telemetría ───────────────────────────────────────────
        if ($dias === 0) {
            $io->text('Telemetría: purga deshabilitada (--retencion-dias=0).');
        } else {
            $corte = new DateTimeImmutable(sprintf('-%d days', $dias));
            if ($dryRun) {
                $io->text(sprintf('Telemetría: se purgarían los registros anteriores a %s.', $corte->format('Y-m-d')));
            } else {
                $borrados = $this->telemetriaRepo->purgarAnterioresA($corte);
                $io->text(sprintf(
                    'Telemetría: %d registro(s) anterior(es) a %s purgado(s).',
                    $borrados,
                    $corte->format('Y-m-d'),
                ));
            }
        }

        // ── 3. Alertas vencidas ──────────────────────────────────────────────
        // Nada las desactivaba: quedaban con activa = 1 para siempre, seguían
        // saliendo como vigentes en el dashboard y su mensaje MQTT retenido
        // se le entregaba a cualquier Pi que se reconectara.
        $expiradas = $this->alertaRepo->findActivasExpiradas();

        if ($expiradas === []) {
            $io->text('Alertas: ninguna activa venció.');
        } else {
            foreach ($expiradas as $alerta) {
                $io->text(sprintf(
                    '  <comment>%s</comment> (id=%d) — venció el %s',
                    $alerta->getTitulo(),
                    $alerta->getId(),
                    $alerta->getExpiraEn()?->format('Y-m-d H:i') ?? '—',
                ));
                if (!$dryRun) {
                    $alerta->desactivar();
                }
            }
            if (!$dryRun) {
                $em->flush();
                // Recién después del flush: si la publicación falla, la alerta
                // igual quedó desactivada en la base.
                foreach ($expiradas as $alerta) {
                    $this->alertaPublisher->publicarDesactivacion($alerta);
                }
            }
            $io->warning(sprintf('%d alerta(s) vencida(s) desactivada(s).', count($expiradas)));
        }

        $io->success('Mantenimiento completado.');

        return Command::SUCCESS;
    }
}
