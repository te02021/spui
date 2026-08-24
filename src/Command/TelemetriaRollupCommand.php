<?php

declare(strict_types=1);

namespace SPUI\Command;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Repository\TelemetriaHoraRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Agrega la telemetría cruda en resúmenes horarios.
 *
 * Los reproductores publican una lectura por minuto, pero el CMS siempre las
 * consume agregadas por hora. Este comando consolida esas lecturas en
 * telemetria_hora, lo que permite purgar el detalle crudo a los pocos días y
 * conservar el histórico durante años a 1/60 del espacio.
 *
 * Debe correr ANTES que spui:mantenimiento: si la purga se adelanta, borra
 * lecturas que todavía no fueron agregadas y esas horas quedan sin resumen.
 *
 * Uso (el monolito exige --id para resolver la app):
 *   php bin/console spui:telemetria:rollup --id=spui
 *   php bin/console spui:telemetria:rollup --id=spui --dry-run
 *   php bin/console spui:telemetria:rollup --id=spui --rehacer   (recalcula todo)
 *
 * Pensado para correr cada hora vía el Programador de tareas de Windows.
 */
#[AsCommand(
    name: 'spui:telemetria:rollup',
    description: 'Agrega la telemetria cruda en resumenes horarios (telemetria_hora).',
)]
class TelemetriaRollupCommand extends Command
{
    /**
     * Cuántas horas hacia atrás se recalculan en cada pasada, más allá de la
     * última hora ya agregada.
     *
     * No basta con continuar desde la última hora agregada: una lectura puede
     * llegar tarde (el fallback HTTP del cliente reintenta, y un reproductor
     * que estuvo sin red puede publicar con retraso), y esa hora ya cerrada
     * quedaría con un resumen incompleto. Al reprocesar unas horas hacia atrás
     * en cada pasada, esos datos tardíos se incorporan solos. Es barato porque
     * el ON DUPLICATE KEY UPDATE simplemente recalcula.
     */
    private const HORAS_SOLAPE = 3;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TelemetriaHoraRepository $rollupRepo,
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

        $desde = $this->resolverDesde($rehacer);

        if ($desde === null) {
            $io->info('No hay telemetria cruda para agregar.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Agregando desde: %s', $desde->format('Y-m-d H:i')));
        $io->text(sprintf('Hasta:           %s (la hora en curso se agrega en la proxima pasada)',
            (new DateTimeImmutable())->format('Y-m-d H:00')));

        if ($dryRun) {
            $pendientes = $this->contarHorasPendientes($desde);
            $io->warning(sprintf('[dry-run] Se agregarian %d hora(s). No se escribio nada.', $pendientes));
            return Command::SUCCESS;
        }

        $filas = $this->rollupRepo->agregarHorasCerradas($desde);

        // MySQL devuelve 1 por fila insertada y 2 por fila actualizada, así que
        // el número no es un conteo exacto de horas; sirve para saber si hubo
        // trabajo, no para reportarlo como cantidad de horas.
        if ($filas === 0) {
            $io->success('Sin horas nuevas para agregar.');
        } else {
            $io->success(sprintf('Rollup completado (%d fila(s) afectada(s)).', $filas));
        }

        return Command::SUCCESS;
    }

    /**
     * Desde qué momento hay que agregar.
     *
     * Con --rehacer se parte de la lectura cruda más antigua. En la ejecución
     * normal se continúa desde la última hora ya agregada, restando el solape
     * para recoger lecturas que hayan llegado tarde.
     */
    private function resolverDesde(bool $rehacer): ?DateTimeImmutable
    {
        if ($rehacer) {
            return $this->primeraLecturaCruda();
        }

        $ultima = $this->rollupRepo->ultimaHoraAgregada();

        if ($ultima === null) {
            // Tabla de rollup vacía: primera ejecución o backfill tras la
            // migración. Se agrega todo lo que haya.
            return $this->primeraLecturaCruda();
        }

        return $ultima->modify(sprintf('-%d hours', self::HORAS_SOLAPE));
    }

    private function primeraLecturaCruda(): ?DateTimeImmutable
    {
        $valor = $this->conexion()
            ->executeQuery('SELECT MIN(registrado_en) FROM telemetria')
            ->fetchOne();

        return $valor ? new DateTimeImmutable((string) $valor) : null;
    }

    private function contarHorasPendientes(DateTimeImmutable $desde): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) FROM (
                SELECT 1
                  FROM telemetria
                 WHERE registrado_en >= :desde
                   AND registrado_en < :hora_actual
                 GROUP BY reproductor_id, DATE_FORMAT(registrado_en, '%Y-%m-%d %H:00:00')
            ) AS horas
            SQL;

        return (int) $this->conexion()->executeQuery($sql, [
            'desde'       => $desde->format('Y-m-d H:i:s'),
            'hora_actual' => (new DateTimeImmutable())->format('Y-m-d H:00:00'),
        ])->fetchOne();
    }

    private function conexion(): Connection
    {
        return $this->doctrine->getManager('SPUI')->getConnection();
    }
}
