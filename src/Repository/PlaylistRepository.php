<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Playlist;

/** @extends ServiceEntityRepository<Playlist> */
class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playlist::class);
    }

    /** @return Playlist[] */
    public function findActivas(): array
    {
        return $this->findBy(['activo' => true], ['nombre' => 'ASC']);
    }
}
