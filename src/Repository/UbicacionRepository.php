<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Ubicacion;

/** @extends ServiceEntityRepository<Ubicacion> */
class UbicacionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ubicacion::class);
    }

    /** @return Ubicacion[] */
    public function findActivas(): array
    {
        return $this->findBy(['activo' => true], ['edificio' => 'ASC', 'aula' => 'ASC']);
    }
}
