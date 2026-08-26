<?php

declare(strict_types=1);

namespace SPUI\Command;

use SPUI\Service\MantenimientoService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tareas periódicas de mantenimiento de la red de reproductores.
 *
 * 1. Marca como 'desconectado' los reproductores cuyo heartbeat venció.
 * 2. Purga la telemetría vieja según la política de retención del proyecto.
 * 3. Desactiva las alertas de emergencia vencidas.
 *
 * La lógica vive en MantenimientoService — este comando es un wrapper para
 * uso manual/diagnóstico (--dry-run incluido). En operación normal NO hace
 * falta cronear esto: spui:mqtt:subscribe lo dispara solo cada minuto
 * mientras corre como servicio (ver MqttSubscribeCommand y
 * config/servicios/). Correrlo a mano sirve para inspeccionar el estado sin
 * esperar al próximo ciclo del daemon, o si el daemon no está disponible.
 *
 * Uso (el monolito exige --id para resolver la app):
 *   php bin/console spui:mantenimiento --id=spui
 *   php bin/console spui:mantenimiento --id=spui --minutos=10 --retencion-dias=90
 *   php bin/console spui:mantenimiento --id=spui --dry-run
 */
#[AsCommand(
    name: 'spui:mantenimiento',
    description: 'Marca reproductores sin heartbeat como desconectados y purga telemetría vieja.',
)]
class MantenimientoCommand extends Command
{
    public function __construct(
        private readonly MantenimientoService $mantenimiento,
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
                (string) MantenimientoService::RETENCION_DIAS_DEFAULT,
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

        $general    = $this->mantenimiento->ejecutarGeneral($segundos, $dias, $dryRun);
        $expiradas  = $this->mantenimiento->revisarAlertasVencidas($dryRun);

        // ── 1. Reproductores caídos ──────────────────────────────────────────
        if ($general['caidos'] === []) {
            $io->text(sprintf('Reproductores: ninguno superó los %d s sin heartbeat.', $segundos));
        } else {
            foreach ($general['caidos'] as $r) {
                $io->text(sprintf(
                    '  <comment>%s</comment> (id=%d) — último heartbeat: %s',
                    $r['hostname'],
                    $r['id'],
                    $r['ultimo_heartbeat'] ?? 'nunca',
                ));
            }
            $io->warning(sprintf(
                '%d reproductor(es) marcado(s) como desconectado(s) tras %d s sin heartbeat.',
                count($general['caidos']),
                $segundos,
            ));
        }

        // ── 2. Purga de telemetría ───────────────────────────────────────────
        if ($general['telemetria_purga_deshabilitada']) {
            $io->text('Telemetría: purga deshabilitada (--retencion-dias=0).');
        } elseif ($dryRun) {
            $corte = (new \DateTimeImmutable(sprintf('-%d days', $dias)))->format('Y-m-d');
            $io->text(sprintf('Telemetría: se purgarían los registros anteriores a %s.', $corte));
        } else {
            $corte = (new \DateTimeImmutable(sprintf('-%d days', $dias)))->format('Y-m-d');
            $io->text(sprintf(
                'Telemetría: %d registro(s) anterior(es) a %s purgado(s).',
                $general['telemetria_purgada'],
                $corte,
            ));
        }

        // ── 3. Alertas vencidas ──────────────────────────────────────────────
        if ($expiradas === []) {
            $io->text('Alertas: ninguna activa venció.');
        } else {
            foreach ($expiradas as $a) {
                $io->text(sprintf(
                    '  <comment>%s</comment> (id=%d) — venció el %s',
                    $a['titulo'],
                    $a['id'],
                    $a['expiraba'] ?? '—',
                ));
            }
            $io->warning(sprintf('%d alerta(s) vencida(s) desactivada(s).', count($expiradas)));
        }

        $io->success('Mantenimiento completado.');

        return Command::SUCCESS;
    }
}
