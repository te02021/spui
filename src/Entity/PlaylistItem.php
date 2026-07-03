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

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true], nullable: true)]
    private ?int $duracionOverrideSeg = null;

    public function getId(): ?int { return $this->id; }

    public function getPlaylist(): Playlist { return $this->playlist; }
    public function setPlaylist(Playlist $playlist): static { $this->playlist = $playlist; return $this; }

    public function getContenido(): Contenido { return $this->contenido; }
    public function setContenido(Contenido $contenido): static { $this->contenido = $contenido; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): static { $this->orden = $orden; return $this; }

    public function getDuracionOverrideSeg(): ?int { return $this->duracionOverrideSeg; }
    public function setDuracionOverrideSeg(?int $duracionOverrideSeg): static { $this->duracionOverrideSeg = $duracionOverrideSeg; return $this; }

    public function getDuracionEfectiva(): ?int
    {
        return $this->duracionOverrideSeg ?? $this->contenido->getDuracionSegundos();
    }
}
