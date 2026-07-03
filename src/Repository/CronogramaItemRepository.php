<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\CronogramaItem;
use SPUI\Entity\Contenido;

/** @extends ServiceEntityRepository<CronogramaItem> */
class CronogramaItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CronogramaItem::class);
    }

    /** @return CronogramaItem[] — solo activos, para la Pi y para filtrado */
    public function findByContenidoOrdenado(Contenido $contenido): array
    {
        return $this->createQueryBuilder('ci')
            ->where('ci.contenido = :contenido')
            ->andWhere('ci.activo = true')
            ->setParameter('contenido', $contenido)
            ->orderBy('ci.orden', 'ASC')
            ->addOrderBy('ci.horaInicio', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return CronogramaItem[] — todos (activos e inactivos), ordenados por horaInicio para el CMS */
    public function findAllByContenidoOrdenado(Contenido $contenido): array
    {
        return $this->createQueryBuilder('ci')
            ->where('ci.contenido = :contenido')
            ->setParameter('contenido', $contenido)
            ->orderBy('ci.horaInicio', 'ASC')
            ->addOrderBy('ci.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
