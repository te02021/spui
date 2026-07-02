<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\ProgramacionEnergeticaRepository;

#[ORM\Entity(repositoryClass: ProgramacionEnergeticaRepository::class)]
#[ORM\Table(name: 'programacion_energetica')]
#[ORM\UniqueConstraint(name: 'uq_pantalla_dia', columns: ['pantalla_id', 'dia_semana'])]
class ProgramacionEnergetica
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pantalla::class, inversedBy: 'programacionesEnergeticas')]
    #[ORM\JoinColumn(nullable: false)]
    private Pantalla $pantalla;

    /** 1=Lunes ... 7=Domingo (ISO-8601) */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $diaSemana;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $horaEncendido;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $horaApagado;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $nivelBrillo = 100;

    public function getId(): ?int { return $this->id; }

    public function getPantalla(): Pantalla { return $this->pantalla; }
    public function setPantalla(Pantalla $pantalla): static { $this->pantalla = $pantalla; return $this; }

    public function getDiaSemana(): int { return $this->diaSemana; }
    public function setDiaSemana(int $diaSemana): static { $this->diaSemana = $diaSemana; return $this; }

    public function getHoraEncendido(): DateTimeImmutable { return $this->horaEncendido; }
    public function setHoraEncendido(DateTimeImmutable $horaEncendido): static { $this->horaEncendido = $horaEncendido; return $this; }

    public function getHoraApagado(): DateTimeImmutable { return $this->horaApagado; }
    public function setHoraApagado(DateTimeImmutable $horaApagado): static { $this->horaApagado = $horaApagado; return $this; }

    public function getNivelBrillo(): int { return $this->nivelBrillo; }
    public function setNivelBrillo(int $nivelBrillo): static { $this->nivelBrillo = max(0, min(100, $nivelBrillo)); return $this; }
}
