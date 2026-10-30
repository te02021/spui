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

        // La última lectura de cada equipo: la de id más alto (las filas entran
        // en orden de llegada). Antes se traían las últimas N×20 de toda la red
        // y se tomaba la primera de cada uno, pero con muchos equipos
        // reportando cada minuto, uno que había dejado de reportar quedaba
        // fuera de esa ventana y figuraba como "todavía no envió telemetría"
        // (encontrado con 18 reproductores de prueba, 30/10/2026).
        $ultimos = $this->createQueryBuilder('t2')
            ->select('MAX(t2.id)')
            ->where('t2.reproductor IN (:reproductores)')
            ->groupBy('t2.reproductor')
            ->getDQL();
        $filas = $this->createQueryBuilder('t')
            ->where("t.id IN ($ultimos)")
            ->setParameter('reproductores', $reproductores)
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
    /**
     * Temperatura promedio por hora de varios reproductores en UNA consulta
     * (el Dashboard la pide cada 20 s por cada navegador abierto: con una
     * consulta por equipo eran N). Mismo detalle crudo que findSeriePorHora(),
     * así que vale para rangos cortos.
     *
     * @param Reproductor[] $reproductores
     * @return array<int, list<array{0: string, 1: float}>> id del reproductor => [[periodo, temp_prom], ...]
     */
    public function findTemperaturaPorHoraDeVarios(array $reproductores, DateTimeImmutable $desde): array
    {
        $ids = array_values(array_filter(array_map(static fn(Reproductor $r) => $r->getId(), $reproductores)));
        if ($ids === []) {
            return [];
        }
        $sql = <<<'SQL'
            SELECT reproductor_id,
                   DATE_FORMAT(registrado_en, '%Y-%m-%d %H:00') AS periodo,
                   AVG(temperatura_soc_celsius)                 AS temp_prom
              FROM telemetria
             WHERE reproductor_id IN (:ids)
               AND registrado_en >= :desde
             GROUP BY reproductor_id, periodo
             ORDER BY reproductor_id, periodo
            SQL;
        $filas = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            ['ids' => $ids, 'desde' => $desde->format('Y-m-d H:i:s')],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $series = [];
        foreach ($filas as $f) {
            $series[(int) $f['reproductor_id']][] = [$f['periodo'], round((float) $f['temp_prom'], 1)];
        }
        return $series;
    }

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
