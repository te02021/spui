<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\CronogramaItemRepository;

#[ORM\Entity(repositoryClass: CronogramaItemRepository::class)]
#[ORM\Table(name: 'cronograma_item')]
class CronogramaItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contenido::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contenido $contenido;

    #[ORM\Column(length: 200)]
    private string $nombre;

    /** Aula o espacio físico donde ocurre el evento — Ej: "Aula 3", "Lab 2", "SUM" */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $aula = null;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $horaInicio;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $horaFin;

    /** Bitmask: bit0=Lun…bit6=Dom — igual que Programacion.diasSemana */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $diasSemana = 127;

    #[ORM\Column]
    private bool $activo = true;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $orden = 0;

    public function getId(): ?int { return $this->id; }

    public function getContenido(): Contenido { return $this->contenido; }
    public function setContenido(Contenido $contenido): static { $this->contenido = $contenido; return $this; }

    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): static { $this->nombre = $nombre; return $this; }

    public function getAula(): ?string { return $this->aula; }
    public function setAula(?string $aula): static { $this->aula = $aula; return $this; }

    public function getHoraInicio(): DateTimeImmutable { return $this->horaInicio; }
    public function setHoraInicio(DateTimeImmutable $h): static { $this->horaInicio = $h; return $this; }

    public function getHoraFin(): DateTimeImmutable { return $this->horaFin; }
    public function setHoraFin(DateTimeImmutable $h): static { $this->horaFin = $h; return $this; }

    public function getDiasSemana(): int { return $this->diasSemana; }
    public function setDiasSemana(int $d): static { $this->diasSemana = $d; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): static { $this->activo = $activo; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): static { $this->orden = $orden; return $this; }

    public function aplicaHoy(): bool
    {
        $bit = (int)(new DateTimeImmutable())->format('N') - 1;
        return (bool)($this->diasSemana & (1 << $bit));
    }
}
