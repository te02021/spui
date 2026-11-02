<?php

declare(strict_types=1);

namespace SPUI\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Contenido;
use SPUI\Entity\Edificio;
use SPUI\Enum\EstadoContenido;
use SPUI\Enum\OrigenCronograma;
use SPUI\Enum\TipoContenido;

/** @extends ServiceEntityRepository<Contenido> */
class ContenidoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contenido::class);
    }

    /** @return Contenido[] */
    public function findDisponibles(): array
    {
        return $this->findBy(['estado' => EstadoContenido::Disponible]);
    }

    /**
     * El cronograma que muestra uno "del edificio de la pantalla" en ese
     * edificio y ese día: el propio, disponible y vigente; si hay más de uno
     * (un cuatrimestre que se superpone con el siguiente), el último modificado.
     */
    public function cronogramaVigenteDeEdificio(Edificio $edificio, DateTimeImmutable $dia): ?Contenido
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tipo = :tipo')->setParameter('tipo', TipoContenido::Cronograma)
            ->andWhere('c.cronogramaOrigen = :propio')->setParameter('propio', OrigenCronograma::Propio)
            ->andWhere('c.cronogramaEdificio = :edificio')->setParameter('edificio', $edificio)
            ->andWhere('c.estado = :disponible')->setParameter('disponible', EstadoContenido::Disponible)
            ->andWhere('c.vigenciaDesde IS NULL OR c.vigenciaDesde <= :dia')
            ->andWhere('c.vigenciaHasta IS NULL OR c.vigenciaHasta >= :dia')
            ->setParameter('dia', $dia->setTime(0, 0), 'date_immutable')
            ->orderBy('c.actualizadoEn', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
