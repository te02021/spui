<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Pantalla;
use SPUI\Entity\ProgramacionEnergetica;

/** @extends ServiceEntityRepository<ProgramacionEnergetica> */
class ProgramacionEnergeticaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramacionEnergetica::class);
    }

    /**
     * Las 7 reglas de una pantalla, de lunes a domingo.
     *
     * @return ProgramacionEnergetica[]
     */
    public function findByPantallaOrdenado(Pantalla $pantalla): array
    {
        return $this->createQueryBuilder('pe')
            ->where('pe.pantalla = :pantalla')
            ->setParameter('pantalla', $pantalla)
            ->orderBy('pe.diaSemana', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Indexadas por día ISO (1=Lunes … 7=Domingo), para armar la grilla del CMS
     * sin tener que buscar dentro del array en cada fila.
     *
     * @return array<int, ProgramacionEnergetica>
     */
    public function findByPantallaIndexadoPorDia(Pantalla $pantalla): array
    {
        $porDia = [];
        foreach ($this->findByPantallaOrdenado($pantalla) as $regla) {
            $porDia[$regla->getDiaSemana()] = $regla;
        }

        return $porDia;
    }
}
