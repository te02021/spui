<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Pantalla;
use SPUI\Enum\EstadoPantalla;

/** @extends ServiceEntityRepository<Pantalla> */
class PantallaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pantalla::class);
    }

    /** @return Pantalla[] */
    public function findActivas(): array
    {
        return $this->findBy(['estado' => EstadoPantalla::Activo]);
    }
}
