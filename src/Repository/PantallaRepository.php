<?php

declare(strict_types=1);

namespace SPUI\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Pantalla;
use SPUI\Entity\Playlist;
use SPUI\Enum\EstadoPantalla;

/** @extends ServiceEntityRepository<Pantalla> */
class PantallaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pantalla::class);
    }

    /** @return Pantalla[] */
    public function findActivas(): array
    {
        return $this->findBy(['estado' => EstadoPantalla::Activo]);
    }

    /**
     * Pantallas que usan esta playlist como respaldo.
     *
     * Playlist no tiene relación inversa hacia Pantalla, así que sin esta
     * consulta no hay forma de saber quién la referencia. Hace falta antes de
     * borrarla: la FK playlist_fallback_id no tiene ON DELETE, y el intento
     * termina en un error SQL crudo de restricción de integridad en vez de un
     * mensaje que el operador pueda entender.
     *
     * @return Pantalla[]
     */
    public function findQueUsanPlaylistComoFallback(Playlist $playlist): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.playlistFallback = :playlist')
            ->setParameter('playlist', $playlist)
            ->orderBy('p.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
