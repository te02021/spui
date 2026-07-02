<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Reproductor;

/** @extends ServiceEntityRepository<Reproductor> */
class ReproductorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reproductor::class);
    }

    public function findByApiKeyHash(string $rawKey): ?Reproductor
    {
        return $this->findOneBy(['apiKeyHash' => hash('sha256', $rawKey)]);
    }
}
