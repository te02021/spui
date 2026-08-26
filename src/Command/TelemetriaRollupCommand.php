<?php

declare(strict_types=1);

namespace SPUI\Command;

use SPUI\Service\TelemetriaRollupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Agrega la telemetría cruda en resúmenes horarios.
 *
 * La lógica vive en TelemetriaRollupService — este comando es un wrapper
 * para uso manual/diagnóstico (--dry-run, --rehacer). En operación normal NO
 * hace falta cronear esto: spui:mqtt:subscribe lo dispara solo cada hora
 * mientras corre como servicio (ver MqttSubscribeCommand y
 * config/servicios/).
 *
 * Uso (el monolito exige --id para resolver la app):
 *   php bin/console spui:telemetria:rollup --id=spui
 *   php bin/console spui:telemetria:rollup --id=spui --dry-run
 *   php bin/console spui:telemetria:rollup --id=spui --rehacer   (recalcula todo)
 */
#[AsCommand(
    name: 'spui:telemetria:rollup',
    description: 'Agrega la telemetria cruda en resumenes horarios (telemetria_hora).',
)]
class TelemetriaRollupCommand extends Command
{
    public function __construct(
        private readonly TelemetriaRollupService $rollup,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Muestra que se agregaria sin escribir nada.',
            )
            ->addOption(
                'rehacer',
                null,
                InputOption::VALUE_NONE,
                'Recalcula desde la lectura cruda mas antigua, no solo lo pendiente.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $rehacer = (bool) $input->getOption('rehacer');

        $io->title('SPUI - Rollup horario de telemetria');

        $resultado = $this->rollup->ejecutar($rehacer, $dryRun);

        if ($resultado['desde'] === null) {
            $io->info('No hay telemetria cruda para agregar.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Agregando desde: %s', $resultado['desde']->format('Y-m-d H:i')));
        $io->text(sprintf('Hasta:           %s (la hora en curso se agrega en la proxima pasada)',
            (new \DateTimeImmutable())->format('Y-m-d H:00')));

        if ($dryRun) {
            $pendientes = $this->rollup->contarHorasPendientes($resultado['desde']);
            $io->warning(sprintf('[dry-run] Se agregarian %d hora(s). No se escribio nada.', $pendientes));
            return Command::SUCCESS;
        }

        // MySQL devuelve 1 por fila insertada y 2 por fila actualizada, así que
        // el número no es un conteo exacto de horas; sirve para saber si hubo
        // trabajo, no para reportarlo como cantidad de horas.
        if ($resultado['filas_afectadas'] === 0) {
            $io->success('Sin horas nuevas para agregar.');
        } else {
            $io->success(sprintf('Rollup completado (%d fila(s) afectada(s)).', $resultado['filas_afectadas']));
        }

        return Command::SUCCESS;
    }
}
