<?php

declare(strict_types=1);

namespace SPUI\Entity;

use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\PlaylistItemRepository;

#[ORM\Entity(repositoryClass: PlaylistItemRepository::class)]
#[ORM\Table(name: 'playlist_item')]
#[ORM\UniqueConstraint(name: 'uq_playlist_orden', columns: ['playlist_id', 'orden'])]
class PlaylistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Playlist::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private Playlist $playlist;

    #[ORM\ManyToOne(targetEntity: Contenido::class, inversedBy: 'playlistItems')]
    #[ORM\JoinColumn(nullable: false)]
    private Contenido $contenido;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $orden;

    public function getId(): ?int { return $this->id; }

    public function getPlaylist(): Playlist { return $this->playlist; }
    public function setPlaylist(Playlist $playlist): static { $this->playlist = $playlist; return $this; }

    public function getContenido(): Contenido { return $this->contenido; }
    public function setContenido(Contenido $contenido): static { $this->contenido = $contenido; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): static { $this->orden = $orden; return $this; }

    /**
     * Segundos que dura este ítem, o null si el contenido es permanente.
     *
     * La duración la define el contenido y sólo el contenido: antes existía un
     * duracion_override_seg por ítem que permitía pisarla en cada playlist, y
     * eso dejaba dos fuentes de verdad para el mismo dato.
     *
     * El método sobrevive aunque hoy sea un passthrough: es el contrato que
     * consumen SyncController y la API de playlists, y mantiene al cliente Pi
     * aislado de cómo el CMS resuelve la duración.
     */
    public function getDuracionEfectiva(): ?int
    {
        return $this->contenido->getDuracionSegundos();
    }
}
