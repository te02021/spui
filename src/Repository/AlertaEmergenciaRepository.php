<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\AlertaEmergencia;

/** @extends ServiceEntityRepository<AlertaEmergencia> */
class AlertaEmergenciaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertaEmergencia::class);
    }

    public function findActivaConMayorPrioridad(): ?AlertaEmergencia
    {
        return $this->createQueryBuilder('a')
            ->where('a.activa = true')
            ->orderBy('a.prioridad', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
