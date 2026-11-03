<?php

declare(strict_types=1);

namespace SPUI\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Reproductor;
use SPUI\Entity\TelemetriaHora;

/** @extends ServiceEntityRepository<TelemetriaHora> */
class TelemetriaHoraRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelemetriaHora::class);
    }

    /**
     * Agrega en telemetria_hora todas las horas CERRADAS que falten.
     *
     * Se salta la hora en curso a propósito: agregarla daría un promedio
     * parcial que después habría que corregir, y como el rollup corre cada
     * hora, esa hora entra en la pasada siguiente.
     *
     * El INSERT ... ON DUPLICATE KEY UPDATE lo hace idempotente: correrlo dos
     * veces seguidas recalcula los mismos valores en vez de duplicar filas o
     * fallar por la clave única. Eso importa porque el comando puede
     * dispararse de más (reintento del programador de tareas, ejecución
     * manual) y no debe hacer daño.
     *
     * Todo ocurre dentro de la base — no se traen las lecturas a PHP para
     * promediarlas. Con 50 nodos, una pasada mueve millones de filas del lado
     * del motor y devuelve sólo el conteo.
     *
     * @param  DateTimeImmutable $desde Sólo agrega horas posteriores a esta marca.
     * @return int Cantidad de filas insertadas o actualizadas.
     */
    public function agregarHorasCerradas(DateTimeImmutable $desde): int
    {
        // DATE_FORMAT con '%Y-%m-%d %H:00:00' trunca a la hora. Es la misma
        // expresión que usaba findSeriePorHora() para agrupar, así que los
        // valores agregados coinciden exactamente con los que ya se mostraban.
        $sql = <<<'SQL'
            INSERT INTO telemetria_hora
                (reproductor_id, hora, temp_prom, temp_max, ram_prom, ram_max, disco_min, cpu_prom, cpu_max, lat_prom, lecturas)
            SELECT reproductor_id,
                   DATE_FORMAT(registrado_en, '%Y-%m-%d %H:00:00') AS hora,
                   AVG(temperatura_soc_celsius),
                   MAX(temperatura_soc_celsius),
                   AVG(uso_ram_porcentaje),
                   MAX(uso_ram_porcentaje),
                   MIN(espacio_disco_libre_mb),
                   AVG(uso_cpu_porcentaje),
                   MAX(uso_cpu_porcentaje),
                   AVG(latencia_red_ms),
                   COUNT(*)
              FROM telemetria
             WHERE registrado_en >= :desde
               AND registrado_en < :hora_actual
             GROUP BY reproductor_id, hora
            ON DUPLICATE KEY UPDATE
                temp_prom = VALUES(temp_prom),
                temp_max  = VALUES(temp_max),
                ram_prom  = VALUES(ram_prom),
                ram_max   = VALUES(ram_max),
                disco_min = VALUES(disco_min),
                cpu_prom  = VALUES(cpu_prom),
                cpu_max   = VALUES(cpu_max),
                lat_prom  = VALUES(lat_prom),
                lecturas  = VALUES(lecturas)
            SQL;

        $horaActual = (new DateTimeImmutable())->format('Y-m-d H:00:00');

        return (int) $this->getEntityManager()->getConnection()->executeStatement($sql, [
            'desde'        => $desde->format('Y-m-d H:i:s'),
            'hora_actual'  => $horaActual,
        ]);
    }

    /**
     * Última hora ya agregada, para saber desde dónde continuar.
     *
     * Devuelve null si la tabla está vacía, caso en que el comando hace el
     * backfill completo desde la lectura cruda más antigua.
     */
    /** Retención del detalle por hora (MantenimientoService::RETENCION_HORA_DIAS). */
    public function purgarAnterioresA(DateTimeImmutable $fecha): int
    {
        return $this->createQueryBuilder('th')
            ->delete()
            ->where('th.hora < :fecha')
            ->setParameter('fecha', $fecha)
            ->getQuery()
            ->execute();
    }

    /**
     * Detalle por hora entre dos fechas, para exportar.
     *
     * @return array<int, array{hora:DateTimeImmutable, temp_prom:float, temp_max:float, ram_prom:float, ram_max:float, disco_min:int, lecturas:int}>
     */
    public function findEntre(Reproductor $reproductor, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $filas = $this->createQueryBuilder('th')
            ->where('th.reproductor = :reproductor')
            ->andWhere('th.hora >= :desde')
            ->andWhere('th.hora < :hasta')
            ->setParameter('reproductor', $reproductor)
            ->setParameter('desde', $desde)
            ->setParameter('hasta', $hasta)
            ->orderBy('th.hora', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn(TelemetriaHora $th) => [
            'hora'      => $th->getHora(),
            'temp_prom' => round($th->getTempProm(), 1),
            'temp_max'  => round($th->getTempMax(), 1),
            'ram_prom'  => round($th->getRamProm(), 1),
            'ram_max'   => round($th->getRamMax(), 1),
            'disco_min' => $th->getDiscoMin(),
            'cpu_prom'  => $th->getCpuProm() !== null ? round($th->getCpuProm(), 1) : null,
            'cpu_max'   => $th->getCpuMax() !== null ? round($th->getCpuMax(), 1) : null,
            'lecturas'  => $th->getLecturas(),
        ], $filas);
    }

    /** La columna del resumen por hora de cada variable del gráfico del Dashboard. */
    private const COLUMNA_POR_VARIABLE = [
        'temp' => 'temp_prom', 'cpu' => 'cpu_prom', 'ram' => 'ram_prom', 'disco' => 'disco_min', 'latencia' => 'lat_prom',
    ];

    /**
     * Una variable de varios reproductores, de los resúmenes por hora (para
     * los 30 días del Dashboard: el crudo se purga a los 7).
     *
     * @param Reproductor[] $reproductores
     * @return array<int, list<array{0: string, 1: float}>> id del reproductor => [[periodo, valor], ...]
     */
    public function findPorHoraDeVarios(array $reproductores, DateTimeImmutable $desde, DateTimeImmutable $hasta, string $variable): array
    {
        $ids = array_values(array_filter(array_map(static fn(Reproductor $r) => $r->getId(), $reproductores)));
        $columna = self::COLUMNA_POR_VARIABLE[$variable] ?? null;
        if ($ids === [] || $columna === null) {
            return [];
        }
        $filas = $this->getEntityManager()->getConnection()->executeQuery(
            sprintf(<<<'SQL'
                SELECT reproductor_id, DATE_FORMAT(hora, '%%Y-%%m-%%d %%H:00') AS periodo, %s AS valor
                  FROM telemetria_hora
                 WHERE reproductor_id IN (:ids)
                   AND hora >= :desde
                   AND hora <= :hasta
                   AND %s IS NOT NULL
                 ORDER BY reproductor_id, hora
                SQL, $columna, $columna),
            ['ids' => $ids, 'desde' => $desde->format('Y-m-d H:i:s'), 'hasta' => $hasta->format('Y-m-d H:i:s')],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $series = [];
        foreach ($filas as $f) {
            $series[(int) $f['reproductor_id']][] = [$f['periodo'], round((float) $f['valor'], 1)];
        }
        return $series;
    }

    public function ultimaHoraAgregada(): ?DateTimeImmutable
    {
        $valor = $this->createQueryBuilder('th')
            ->select('MAX(th.hora)')
            ->getQuery()
            ->getSingleScalarResult();

        return $valor !== null ? new DateTimeImmutable((string) $valor) : null;
    }

    /**
     * Serie por hora para los gráficos, leída del rollup.
     *
     * Devuelve exactamente el mismo shape que
     * TelemetriaRepository::findSeriePorHora(), para que el controlador y los
     * templates no noten de dónde salieron los datos.
     *
     * @return array<int, array{periodo:string, temp_prom:float, temp_max:float, ram_prom:float, disco_min:int, lecturas:int}>
     */
    public function findSerie(Reproductor $reproductor, DateTimeImmutable $desde): array
    {
        $filas = $this->createQueryBuilder('th')
            ->where('th.reproductor = :reproductor')
            ->andWhere('th.hora >= :desde')
            ->setParameter('reproductor', $reproductor)
            ->setParameter('desde', $desde)
            ->orderBy('th.hora', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn(TelemetriaHora $th) => [
            'periodo'   => $th->getHora()->format('Y-m-d H:00'),
            'temp_prom' => round($th->getTempProm(), 1),
            'temp_max'  => round($th->getTempMax(), 1),
            'ram_prom'  => round($th->getRamProm(), 1),
            'cpu_prom'  => $th->getCpuProm() !== null ? round($th->getCpuProm(), 1) : null,
            'disco_min' => $th->getDiscoMin(),
            'lecturas'  => $th->getLecturas(),
        ], $filas);
    }

    /**
     * Resumen del período a partir del rollup.
     *
     * El promedio se pondera por la cantidad de lecturas de cada hora
     * (SUM(prom*lecturas)/SUM(lecturas)) en vez de promediar los promedios: si
     * un equipo estuvo caído media hora, esa hora tiene 30 lecturas y no 60, y
     * un promedio simple le daría el mismo peso que a una hora completa.
     *
     * @return array{lecturas:int, temp_prom:float, temp_max:float, ram_prom:float, ram_max:float, disco_min:int}|null
     */
    public function resumen(Reproductor $reproductor, DateTimeImmutable $desde, ?DateTimeImmutable $hasta = null): ?array
    {
        $sql = <<<'SQL'
            SELECT SUM(lecturas)                          AS lecturas,
                   SUM(temp_prom * lecturas) / SUM(lecturas) AS temp_prom,
                   MAX(temp_max)                          AS temp_max,
                   SUM(ram_prom * lecturas) / SUM(lecturas)  AS ram_prom,
                   MAX(ram_max)                           AS ram_max,
                   SUM(cpu_prom * lecturas) / NULLIF(SUM(CASE WHEN cpu_prom IS NOT NULL THEN lecturas END), 0) AS cpu_prom,
                   MAX(cpu_max)                           AS cpu_max,
                   MIN(disco_min)                         AS disco_min
              FROM telemetria_hora
             WHERE reproductor_id = :reproductor
               AND hora >= :desde
               AND hora < :hasta
            SQL;

        $f = $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'reproductor' => $reproductor->getId(),
            'desde'       => $desde->format('Y-m-d H:i:s'),
            'hasta'       => ($hasta ?? new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        if (!$f || $f['lecturas'] === null || (int) $f['lecturas'] === 0) {
            return null;
        }

        return [
            'lecturas'  => (int) $f['lecturas'],
            'temp_prom' => round((float) $f['temp_prom'], 1),
            'temp_max'  => round((float) $f['temp_max'], 1),
            'ram_prom'  => round((float) $f['ram_prom'], 1),
            'ram_max'   => round((float) $f['ram_max'], 1),
            // Sólo las horas con el dato (antes del 03/11/2026 no lo hay).
            'cpu_prom'  => $f['cpu_prom'] !== null ? round((float) $f['cpu_prom'], 1) : null,
            'cpu_max'   => $f['cpu_max'] !== null ? round((float) $f['cpu_max'], 1) : null,
            'disco_min' => (int) $f['disco_min'],
        ];
    }
}
