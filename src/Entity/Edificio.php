<?php

declare(strict_types=1);

namespace SPUI\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\EdificioRepository;

#[ORM\Entity(repositoryClass: EdificioRepository::class)]
#[ORM\Table(name: 'edificio')]
class Edificio
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $nombre;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column]
    private bool $activo = true;

    #[ORM\OneToMany(mappedBy: 'edificio', targetEntity: Ubicacion::class)]
    private Collection $ubicaciones;

    #[ORM\OneToMany(mappedBy: 'edificio', targetEntity: Programacion::class)]
    private Collection $programaciones;

    public function __construct()
    {
        $this->ubicaciones    = new ArrayCollection();
        $this->programaciones = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): static { $this->nombre = $nombre; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): static { $this->descripcion = $descripcion; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): static { $this->activo = $activo; return $this; }

    public function getUbicaciones(): Collection { return $this->ubicaciones; }
    public function getProgramaciones(): Collection { return $this->programaciones; }

    public function __toString(): string { return $this->nombre; }
}
