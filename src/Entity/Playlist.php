<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\PlaylistRepository;

#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
#[ORM\Table(name: 'playlist')]
#[ORM\HasLifecycleCallbacks]
class Playlist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $nombre;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column]
    private bool $activo = true;

    /** Referencia cross-DB a Intranet.user — sin FK Doctrine */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $creadoPorId;

    #[ORM\Column]
    private DateTimeImmutable $creadoEn;

    #[ORM\Column]
    private DateTimeImmutable $actualizadoEn;

    #[ORM\OneToMany(mappedBy: 'playlist', targetEntity: PlaylistItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $items;

    #[ORM\OneToMany(mappedBy: 'playlist', targetEntity: Programacion::class)]
    private Collection $programaciones;

    public function __construct()
    {
        $this->creadoEn = new DateTimeImmutable();
        $this->actualizadoEn = new DateTimeImmutable();
        $this->items = new ArrayCollection();
        $this->programaciones = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->actualizadoEn = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): static { $this->nombre = $nombre; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): static { $this->descripcion = $descripcion; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): static { $this->activo = $activo; return $this; }

    public function getCreadoPorId(): int { return $this->creadoPorId; }
    public function setCreadoPorId(int $creadoPorId): static { $this->creadoPorId = $creadoPorId; return $this; }

    public function getCreadoEn(): DateTimeImmutable { return $this->creadoEn; }
    public function getActualizadoEn(): DateTimeImmutable { return $this->actualizadoEn; }

    public function getItems(): Collection { return $this->items; }

    public function addItem(PlaylistItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setPlaylist($this);
        }
        return $this;
    }

    public function removeItem(PlaylistItem $item): static
    {
        $this->items->removeElement($item);
        return $this;
    }

    public function getProgramaciones(): Collection { return $this->programaciones; }
}
