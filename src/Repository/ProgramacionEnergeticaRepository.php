<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\ProgramacionEnergetica;

/** @extends ServiceEntityRepository<ProgramacionEnergetica> */
class ProgramacionEnergeticaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramacionEnergetica::class);
    }
}
