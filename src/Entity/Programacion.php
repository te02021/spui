<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\ProgramacionRepository;

#[ORM\Entity(repositoryClass: ProgramacionRepository::class)]
#[ORM\Table(name: 'programacion')]
class Programacion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    /** Exactamente uno de pantalla/ubicacion/edificio debe estar seteado, o ninguno (global) */
    #[ORM\ManyToOne(targetEntity: Pantalla::class, inversedBy: 'programaciones')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Pantalla $pantalla = null;

    #[ORM\ManyToOne(targetEntity: Ubicacion::class, inversedBy: 'programaciones')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ubicacion $ubicacion = null;

    #[ORM\ManyToOne(targetEntity: Edificio::class, inversedBy: 'programaciones')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Edificio $edificio = null;

    #[ORM\ManyToOne(targetEntity: Playlist::class, inversedBy: 'programaciones')]
    #[ORM\JoinColumn(nullable: false)]
    private Playlist $playlist;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $fechaInicio;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaFin = null;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $horaInicio;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $horaFin;

    /**
     * Bitmask de días: bit0=Lun, bit1=Mar, …, bit6=Dom.
     * Ejemplo: 31 (0b0011111) = Lun-Vie. 127 (0b1111111) = todos los días.
     */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $diasSemana = 31;

    /** true = se repite cada semana en los días del bitmask; false = evento único en el rango fechaInicio-fechaFin */
    #[ORM\Column]
    private bool $repetirSemanal = true;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $prioridad = 5;

    #[ORM\Column]
    private bool $activo = true;

    /** Referencia cross-DB a Intranet.user — sin FK Doctrine */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $creadoPorId;

    #[ORM\Column]
    private DateTimeImmutable $creadoEn;

    public function __construct()
    {
        $this->creadoEn = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getPantalla(): ?Pantalla { return $this->pantalla; }
    public function setPantalla(?Pantalla $pantalla): static { $this->pantalla = $pantalla; return $this; }

    public function getUbicacion(): ?Ubicacion { return $this->ubicacion; }
    public function setUbicacion(?Ubicacion $ubicacion): static { $this->ubicacion = $ubicacion; return $this; }

    public function getEdificio(): ?Edificio { return $this->edificio; }
    public function setEdificio(?Edificio $edificio): static { $this->edificio = $edificio; return $this; }

    public function getPlaylist(): Playlist { return $this->playlist; }
    public function setPlaylist(Playlist $playlist): static { $this->playlist = $playlist; return $this; }

    public function getFechaInicio(): DateTimeImmutable { return $this->fechaInicio; }
    public function setFechaInicio(DateTimeImmutable $fechaInicio): static { $this->fechaInicio = $fechaInicio; return $this; }

    public function getFechaFin(): ?DateTimeImmutable { return $this->fechaFin; }
    public function setFechaFin(?DateTimeImmutable $fechaFin): static { $this->fechaFin = $fechaFin; return $this; }

    public function getHoraInicio(): DateTimeImmutable { return $this->horaInicio; }
    public function setHoraInicio(DateTimeImmutable $horaInicio): static { $this->horaInicio = $horaInicio; return $this; }

    public function getHoraFin(): DateTimeImmutable { return $this->horaFin; }
    public function setHoraFin(DateTimeImmutable $horaFin): static { $this->horaFin = $horaFin; return $this; }

    public function getDiasSemana(): int { return $this->diasSemana; }
    public function setDiasSemana(int $diasSemana): static { $this->diasSemana = $diasSemana; return $this; }

    public function isRepetirSemanal(): bool { return $this->repetirSemanal; }
    public function setRepetirSemanal(bool $repetirSemanal): static { $this->repetirSemanal = $repetirSemanal; return $this; }

    public function getPrioridad(): int { return $this->prioridad; }
    public function setPrioridad(int $prioridad): static { $this->prioridad = $prioridad; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): static { $this->activo = $activo; return $this; }

    public function getCreadoPorId(): int { return $this->creadoPorId; }
    public function setCreadoPorId(int $creadoPorId): static { $this->creadoPorId = $creadoPorId; return $this; }

    public function getCreadoEn(): DateTimeImmutable { return $this->creadoEn; }

    public function aplicaHoy(): bool
    {
        $bit = (int)(new DateTimeImmutable())->format('N') - 1;
        return (bool)($this->diasSemana & (1 << $bit));
    }
}
