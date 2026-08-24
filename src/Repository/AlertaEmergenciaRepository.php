<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\AlertaEmergencia;

/** @extends ServiceEntityRepository<AlertaEmergencia> */
class AlertaEmergenciaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertaEmergencia::class);
    }

    /**
     * Alerta vigente de mayor prioridad.
     *
     * La expiración se filtra acá y no en PHP a propósito: si la de mayor
     * prioridad ya venció, hay que seguir con la siguiente que siga vigente.
     * Filtrando después de traer una sola fila, una alerta vencida "tapaba" a
     * las demás y el sync terminaba devolviendo null.
     */
    public function findActivaConMayorPrioridad(): ?AlertaEmergencia
    {
        return $this->createQueryBuilder('a')
            ->where('a.activa = true')
            ->andWhere('a.expiraEn IS NULL OR a.expiraEn > :ahora')
            ->setParameter('ahora', new \DateTimeImmutable())
            ->orderBy('a.prioridad', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Alertas que siguen marcadas como activas pero cuya fecha de expiración
     * ya pasó. Las usa spui:mantenimiento para desactivarlas: nada lo hacía
     * antes, así que quedaban con activa = 1 para siempre y el dashboard las
     * seguía mostrando como vigentes.
     *
     * @return AlertaEmergencia[]
     */
    public function findActivasExpiradas(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.activa = true')
            ->andWhere('a.expiraEn IS NOT NULL')
            ->andWhere('a.expiraEn <= :ahora')
            ->setParameter('ahora', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
