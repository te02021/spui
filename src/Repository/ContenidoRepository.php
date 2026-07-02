<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Contenido;
use SPUI\Enum\EstadoContenido;

/** @extends ServiceEntityRepository<Contenido> */
class ContenidoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contenido::class);
    }

    /** @return Contenido[] */
    public function findPublicados(): array
    {
        return $this->findBy(['estado' => EstadoContenido::Publicado]);
    }
}
