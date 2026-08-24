<?php

declare(strict_types=1);

namespace SPUI\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Reproductor;
use SPUI\Entity\Telemetria;

/** @extends ServiceEntityRepository<Telemetria> */
class TelemetriaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Telemetria::class);
    }

    /**
     * Últimos N registros de telemetría para un reproductor.
     *
     * @return Telemetria[]
     */
    public function findUltimos(Reproductor $reproductor, int $limit = 60): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.reproductor = :reproductor')
            ->setParameter('reproductor', $reproductor)
            ->orderBy('t.registradoEn', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Última lectura de cada reproductor, en UNA sola consulta.
     *
     * El dashboard antes hacía findUltimos($r, 1) dentro de un foreach, o sea
     * una consulta por reproductor (N+1). Con muchos nodos eso se nota.
     *
     * @param  Reproductor[] $reproductores
     * @return array<int, Telemetria> indexado por id de reproductor
     */
    public function findUltimaPorReproductor(array $reproductores): array
    {
        if ($reproductores === []) {
            return [];
        }

        // Se traen las lecturas recientes de todos los nodos de una vez y se
        // queda la primera de cada uno. El índice (reproductor_id, registrado_en)
        // hace que el ORDER BY salga del índice, sin filesort.
        $filas = $this->createQueryBuilder('t')
            ->where('t.reproductor IN (:reproductores)')
            ->setParameter('reproductores', $reproductores)
            ->orderBy('t.registradoEn', 'DESC')
            ->setMaxResults(count($reproductores) * 20)
            ->getQuery()
            ->getResult();

        $ultima = [];
        foreach ($filas as $fila) {
            $id = $fila->getReproductor()->getId();
            if (!isset($ultima[$id])) {
                $ultima[$id] = $fila;
            }
        }

        return $ultima;
    }

    /**
     * Serie temporal agregada para los gráficos del detalle de nodo.
     *
     * Agrupa por hora en vez de devolver las lecturas crudas: con una lectura
     * por minuto, 7 días son ~10 000 puntos, imposibles de graficar. Por hora
     * quedan 168, que es lo que se quiere ver.
     *
     * OJO — desde que existe telemetria_hora, esta consulta sólo sirve para
     * rangos cortos: el detalle crudo se purga a los pocos días, así que pedir
     * 30 días acá devolvería una serie truncada. Los consumidores deben usar
     * TelemetriaHoraRepository::findSerie(), que lee del rollup. Se conserva
     * para el rango de 24 h, donde el crudo todavía está y evita depender de
     * que el rollup haya corrido.
     *
     * @return array<int, array{periodo:string, temp_prom:float, temp_max:float, ram_prom:float, disco_min:int, lecturas:int}>
     */
    public function findSeriePorHora(Reproductor $reproductor, DateTimeImmutable $desde): array
    {
        $sql = <<<'SQL'
            SELECT DATE_FORMAT(registrado_en, '%Y-%m-%d %H:00') AS periodo,
                   AVG(temperatura_soc_celsius)                 AS temp_prom,
                   MAX(temperatura_soc_celsius)                 AS temp_max,
                   AVG(uso_ram_porcentaje)                      AS ram_prom,
                   MIN(espacio_disco_libre_mb)                  AS disco_min,
                   COUNT(*)                                     AS lecturas
              FROM telemetria
             WHERE reproductor_id = :reproductor
               AND registrado_en >= :desde
             GROUP BY periodo
             ORDER BY periodo ASC
            SQL;

        $filas = $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'reproductor' => $reproductor->getId(),
            'desde'       => $desde->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        return array_map(static fn(array $f) => [
            'periodo'   => $f['periodo'],
            'temp_prom' => round((float) $f['temp_prom'], 1),
            'temp_max'  => round((float) $f['temp_max'], 1),
            'ram_prom'  => round((float) $f['ram_prom'], 1),
            'disco_min' => (int) $f['disco_min'],
            'lecturas'  => (int) $f['lecturas'],
        ], $filas);
    }

    /** Resumen del período, para las tarjetas del detalle de nodo. */
    public function resumen(Reproductor $reproductor, DateTimeImmutable $desde): ?array
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)                        AS lecturas,
                   AVG(temperatura_soc_celsius)    AS temp_prom,
                   MAX(temperatura_soc_celsius)    AS temp_max,
                   AVG(uso_ram_porcentaje)         AS ram_prom,
                   MAX(uso_ram_porcentaje)         AS ram_max,
                   MIN(espacio_disco_libre_mb)     AS disco_min
              FROM telemetria
             WHERE reproductor_id = :reproductor
               AND registrado_en >= :desde
            SQL;

        $f = $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'reproductor' => $reproductor->getId(),
            'desde'       => $desde->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        if (!$f || (int) $f['lecturas'] === 0) {
            return null;
        }

        return [
            'lecturas'  => (int) $f['lecturas'],
            'temp_prom' => round((float) $f['temp_prom'], 1),
            'temp_max'  => round((float) $f['temp_max'], 1),
            'ram_prom'  => round((float) $f['ram_prom'], 1),
            'ram_max'   => round((float) $f['ram_max'], 1),
            'disco_min' => (int) $f['disco_min'],
        ];
    }

    public function purgarAnterioresA(DateTimeImmutable $fecha): int
    {
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('t.registradoEn < :fecha')
            ->setParameter('fecha', $fecha)
            ->getQuery()
            ->execute();
    }
}
