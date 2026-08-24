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
        // Se descartan los ítems sin horario: horaInicio/horaFin son nullable en
        // la entidad (para que el formulario pueda mapear antes de validar) y el
        // sync hace ->format() sobre ellos. Una fila incompleta rompería el sync
        // de ese reproductor. El listado del CMS usa findAllByContenidoOrdenado,
        // que sí las muestra para poder corregirlas.
        return $this->createQueryBuilder('ci')
            ->where('ci.contenido = :contenido')
            ->andWhere('ci.activo = true')
            ->andWhere('ci.horaInicio IS NOT NULL')
            ->andWhere('ci.horaFin IS NOT NULL')
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
