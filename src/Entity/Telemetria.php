<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\TelemetriaRepository;

#[ORM\Entity(repositoryClass: TelemetriaRepository::class)]
#[ORM\Table(name: 'telemetria')]
#[ORM\Index(name: 'idx_reproductor_registrado', columns: ['reproductor_id', 'registrado_en'])]
class Telemetria
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Reproductor::class, inversedBy: 'telemetrias')]
    #[ORM\JoinColumn(nullable: false)]
    private Reproductor $reproductor;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $temperaturaSocCelsius;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $usoRamPorcentaje;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true], nullable: true)]
    private ?int $latenciaRedMs = null;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $espacioDiscoLibreMb;

    #[ORM\Column]
    private DateTimeImmutable $registradoEn;

    public function __construct()
    {
        $this->registradoEn = new DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }

    public function getReproductor(): Reproductor { return $this->reproductor; }
    public function setReproductor(Reproductor $reproductor): static { $this->reproductor = $reproductor; return $this; }

    public function getTemperaturaSocCelsius(): float { return (float) $this->temperaturaSocCelsius; }
    public function setTemperaturaSocCelsius(float $v): static { $this->temperaturaSocCelsius = (string) $v; return $this; }

    public function getUsoRamPorcentaje(): float { return (float) $this->usoRamPorcentaje; }
    public function setUsoRamPorcentaje(float $v): static { $this->usoRamPorcentaje = (string) $v; return $this; }

    public function getLatenciaRedMs(): ?int { return $this->latenciaRedMs; }
    public function setLatenciaRedMs(?int $latenciaRedMs): static { $this->latenciaRedMs = $latenciaRedMs; return $this; }

    public function getEspacioDiscoLibreMb(): int { return $this->espacioDiscoLibreMb; }
    public function setEspacioDiscoLibreMb(int $espacioDiscoLibreMb): static { $this->espacioDiscoLibreMb = $espacioDiscoLibreMb; return $this; }

    public function getRegistradoEn(): DateTimeImmutable { return $this->registradoEn; }
}
