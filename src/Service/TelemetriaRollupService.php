<?php

declare(strict_types=1);

namespace SPUI\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Repository\TelemetriaHoraRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Agrega la telemetría cruda en resúmenes horarios (telemetria_hora).
 *
 * Extraído de TelemetriaRollupCommand para que tanto el comando de consola
 * (uso manual/diagnóstico, con --dry-run y --rehacer) como el daemon
 * spui:mqtt:subscribe (ejecución periódica automática, ver
 * MqttSubscribeCommand) llamen a la misma lógica sin duplicarla.
 *
 * Debe correr ANTES que MantenimientoService::ejecutar(): si la purga de
 * telemetría cruda se adelanta, borra lecturas que todavía no fueron
 * agregadas y esas horas quedan sin resumen.
 */
final class TelemetriaRollupService
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

    /** Margen para un rollup que tarda o un daemon que recién arrancó. */
    private const MARGEN_AVISO_SEG = 600;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TelemetriaHoraRepository $rollupRepo,
        #[Autowire('%env(int:default:spui_intervalo_rollup_default:SPUI_INTERVALO_ROLLUP_SEG)%')]
        private readonly int $intervaloRollupSeg = 3600,
    ) {
    }

    /**
     * @return array{desde: ?DateTimeImmutable, filas_afectadas: int}
     */
    public function ejecutar(bool $rehacer = false, bool $dryRun = false): array
    {
        $desde = $this->resolverDesde($rehacer);

        if ($desde === null) {
            return ['desde' => null, 'filas_afectadas' => 0];
        }

        if ($dryRun) {
            return ['desde' => $desde, 'filas_afectadas' => 0];
        }

        $filas = $this->rollupRepo->agregarHorasCerradas($desde);

        return ['desde' => $desde, 'filas_afectadas' => $filas];
    }

    /**
     * Cuenta cuántas horas se agregarían sin escribir nada — sólo para el
     * modo --dry-run del comando de consola.
     */
    public function contarHorasPendientes(DateTimeImmutable $desde): int
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

    /**
     * Desde qué momento hay que agregar.
     *
     * Con $rehacer se parte de la lectura cruda más antigua. En la ejecución
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

    /**
     * Aviso de "staleness" para el dashboard: ¿el scheduler interno del
     * demonio (tarea 1.7/1.5) dejó de correr?
     *
     * Avisa sólo si hay una lectura cruda que el rollup YA tendría que haber
     * agregado y no lo hizo. Una lectura de las t queda en la hora que cierra
     * en ceil(t); la primera pasada después de ese cierre la agrega, y las
     * pasadas son cada intervaloRollupSeg. Así que, con el daemon andando,
     * ninguna lectura más vieja que 1 h + intervalo queda sin agregar.
     *
     * Antes comparaba la lectura más reciente contra el COMIENZO de la última
     * hora agregada, con un umbral fijo de 2 h: según el minuto en que corría
     * el rollup, durante un rato de cada hora decía "detenido" con el daemon
     * funcionando, y también después de cualquier corte de telemetría (la
     * hora en curso no se agrega hasta que cierra). Mateo lo veía aparecer y
     * desaparecer sin motivo (29/10/2026).
     */
    public function estaDesactualizado(): bool
    {
        $ultimaAgregada = $this->rollupRepo->ultimaHoraAgregada();
        $finAgregado = $ultimaAgregada !== null
            ? $ultimaAgregada->modify('+1 hour')
            : new DateTimeImmutable('@0');
        $limite = new DateTimeImmutable(sprintf('-%d seconds', 3600 + $this->intervaloRollupSeg + self::MARGEN_AVISO_SEG));

        if ($finAgregado >= $limite) {
            return false;
        }

        return (bool) $this->conexion()
            ->executeQuery(
                'SELECT 1 FROM telemetria WHERE registrado_en >= :fin AND registrado_en < :limite LIMIT 1',
                [
                    'fin'    => $finAgregado->format('Y-m-d H:i:s'),
                    'limite' => $limite->format('Y-m-d H:i:s'),
                ],
            )
            ->fetchOne();
    }

    private function conexion(): Connection
    {
        return $this->doctrine->getManager('SPUI')->getConnection();
    }
}
