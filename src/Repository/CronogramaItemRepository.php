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

    /**
     * @return CronogramaItem[] — solo activos, para la Pi y para filtrado
     *
     * Ordena por hora igual que el CMS: un cronograma es una grilla horaria,
     * así que lo que ve el admin tiene que ser lo que muestra la pantalla.
     * 'orden' queda sólo como desempate entre ítems que arrancan a la misma hora.
     */
    public function findByContenidoOrdenado(Contenido $contenido): array
    {
        // Se descartan los ítems sin hora de inicio (una fila incompleta). La
        // hora de fin sí puede faltar desde el 02/11/2026: un evento especial
        // sin hora de fin ("Desde las 18:00"); quien la use tiene que
        // contemplarlo. El listado del CMS usa findAllByContenidoOrdenado,
        // que muestra todo para poder corregirlo.
        return $this->createQueryBuilder('ci')
            ->where('ci.contenido = :contenido')
            ->andWhere('ci.activo = true')
            ->andWhere('ci.horaInicio IS NOT NULL')
            ->setParameter('contenido', $contenido)
            ->orderBy('ci.horaInicio', 'ASC')
            ->addOrderBy('ci.orden', 'ASC')
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
