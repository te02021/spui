<?php

declare(strict_types=1);

namespace SPUI\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Repository\TelemetriaHoraRepository;

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

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TelemetriaHoraRepository $rollupRepo,
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
     * No compara contra "ahora": eso daría falsos positivos apenas arranca
     * cada hora nueva, antes de que le toque el rollup (que a propósito no
     * agrega la hora en curso). Compara la lectura cruda más reciente contra
     * la última hora ya agregada — si hay telemetría entrando pero el rollup
     * no avanza, es que el demonio se cayó o algo lo está bloqueando; si no
     * hay telemetría cruda en absoluto (sin reproductores activos), no hay
     * nada que agregar y no es un problema de este servicio.
     */
    public function estaDesactualizado(): bool
    {
        $ultimaCruda = $this->conexion()
            ->executeQuery('SELECT MAX(registrado_en) FROM telemetria')
            ->fetchOne();

        if (!$ultimaCruda) {
            return false;
        }

        $ultimaCruda    = new DateTimeImmutable((string) $ultimaCruda);
        $ultimaAgregada = $this->rollupRepo->ultimaHoraAgregada();

        $referencia = $ultimaAgregada ?? new DateTimeImmutable('@0');

        return ($ultimaCruda->getTimestamp() - $referencia->getTimestamp()) > 7200;
    }

    private function conexion(): Connection
    {
        return $this->doctrine->getManager('SPUI')->getConnection();
    }
}
