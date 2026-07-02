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
