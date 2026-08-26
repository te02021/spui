<?php

declare(strict_types=1);

namespace SPUI\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Reproductor;
use SPUI\Enum\EstadoConexion;

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

    /**
     * Reproductor cuya clave ANTERIOR (a la última regeneración) matchea la
     * enviada — tarea 1.4. Permite distinguir un intento con la clave vieja
     * de una regeneración reciente de un intento genuinamente desconocido.
     */
    public function findByApiKeyHashAnterior(string $rawKey): ?Reproductor
    {
        return $this->createQueryBuilder('r')
            ->where('r.apiKeyHashAnterior = :hash')
            ->andWhere('r.apiKeyHashAnteriorVenceEn > :ahora')
            ->setParameter('hash', hash('sha256', $rawKey))
            ->setParameter('ahora', new DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Reproductores marcados como conectados cuyo último heartbeat venció.
     *
     * Sólo considera los que alguna vez reportaron: un reproductor sin heartbeat
     * nunca estuvo conectado, así que le corresponde 'sin_registrar', no 'desconectado'.
     *
     * @return Reproductor[]
     */
    public function findConectadosSinHeartbeatDesde(DateTimeImmutable $limite): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.estadoConexion = :conectado')
            ->andWhere('r.ultimoHeartbeat IS NOT NULL')
            ->andWhere('r.ultimoHeartbeat < :limite')
            ->setParameter('conectado', EstadoConexion::Conectado)
            ->setParameter('limite', $limite)
            ->getQuery()
            ->getResult();
    }
}
