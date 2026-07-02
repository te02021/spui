<?php

declare(strict_types=1);

namespace SPUI\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\UbicacionRepository;

#[ORM\Entity(repositoryClass: UbicacionRepository::class)]
#[ORM\Table(name: 'ubicacion')]
class Ubicacion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Edificio::class, inversedBy: 'ubicaciones')]
    #[ORM\JoinColumn(nullable: false)]
    private Edificio $edificio;

    /** Sector o espacio dentro del edificio — Ej: "Hall de Entrada", "Pasillo PB", "Recepción" */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $sector = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column]
    private bool $activo = true;

    #[ORM\OneToMany(mappedBy: 'ubicacion', targetEntity: Pantalla::class)]
    private Collection $pantallas;

    #[ORM\OneToMany(mappedBy: 'ubicacion', targetEntity: Programacion::class)]
    private Collection $programaciones;

    public function __construct()
    {
        $this->pantallas      = new ArrayCollection();
        $this->programaciones = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getEdificio(): Edificio { return $this->edificio; }
    public function setEdificio(Edificio $edificio): static { $this->edificio = $edificio; return $this; }

    public function getSector(): ?string { return $this->sector; }
    public function setSector(?string $sector): static { $this->sector = $sector; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): static { $this->descripcion = $descripcion; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): static { $this->activo = $activo; return $this; }

    public function getPantallas(): Collection { return $this->pantallas; }
    public function getProgramaciones(): Collection { return $this->programaciones; }

    public function __toString(): string
    {
        $base = $this->edificio->getNombre();
        return $this->sector ? $base . ' — ' . $this->sector : $base;
    }
}
