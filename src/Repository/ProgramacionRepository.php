<?php

declare(strict_types=1);

namespace SPUI\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Pantalla;
use SPUI\Entity\Programacion;

/** @extends ServiceEntityRepository<Programacion> */
class ProgramacionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Programacion::class);
    }

    /**
     * Devuelve todas las programaciones activas que podrían aplicar ahora para una pantalla,
     * incluyendo reglas por pantalla, por ubicación/sector, por edificio y globales.
     *
     * Descarta las reglas sin horario o sin playlist: esos campos son nullable
     * en la entidad (los setters aceptan null para que el formulario pueda
     * mapear los datos antes de validarlos), así que una fila incompleta haría
     * reventar el ->format() del sync y ese reproductor se quedaría sin poder
     * sincronizar nunca más. Filtrarlo acá lo cubre para todos los consumidores.
     *
     * @return Programacion[]
     */
    public function findVigentesParaPantalla(Pantalla $pantalla): array
    {
        $hoy = new DateTimeImmutable();

        return $this->createQueryBuilder('p')
            ->where('p.activo = true')
            ->andWhere('p.horaInicio IS NOT NULL')
            ->andWhere('p.horaFin IS NOT NULL')
            ->andWhere('p.playlist IS NOT NULL')
            ->andWhere('p.fechaInicio <= :hoy')
            ->andWhere('p.fechaFin IS NULL OR p.fechaFin >= :hoy')
            ->andWhere(
                'p.pantalla = :pantalla
                OR p.ubicacion = :ubicacion
                OR p.edificio = :edificio
                OR (p.pantalla IS NULL AND p.ubicacion IS NULL AND p.edificio IS NULL)'
            )
            ->setParameter('hoy', $hoy->format('Y-m-d'))
            ->setParameter('pantalla', $pantalla)
            ->setParameter('ubicacion', $pantalla->getUbicacion())
            ->setParameter('edificio', $pantalla->getUbicacion()->getEdificio())
            ->orderBy('p.prioridad', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
