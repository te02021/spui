<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Enum\EstadoPantalla;
use SPUI\Repository\PantallaRepository;

#[ORM\Entity(repositoryClass: PantallaRepository::class)]
#[ORM\Table(name: 'pantalla')]
#[ORM\HasLifecycleCallbacks]
class Pantalla
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $nombre;

    #[ORM\ManyToOne(targetEntity: Ubicacion::class, inversedBy: 'pantallas')]
    #[ORM\JoinColumn(nullable: false)]
    private Ubicacion $ubicacion;

    /** Reproductor (Raspberry Pi) que controla esta pantalla — nullable hasta ser asignado */
    #[ORM\ManyToOne(targetEntity: Reproductor::class, inversedBy: 'pantallas')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Reproductor $reproductor = null;

    /** Playlist que se reproduce cuando ninguna programación aplica en el momento actual */
    #[ORM\ManyToOne(targetEntity: Playlist::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Playlist $playlistFallback = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 17, nullable: true)]
    private ?string $macAddress = null;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $resolucionAncho = 1920;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $resolucionAlto = 1080;

    #[ORM\Column(enumType: EstadoPantalla::class)]
    private EstadoPantalla $estado = EstadoPantalla::Activo;

    #[ORM\Column]
    private DateTimeImmutable $creadoEn;

    #[ORM\Column]
    private DateTimeImmutable $actualizadoEn;

    #[ORM\OneToMany(mappedBy: 'pantalla', targetEntity: Programacion::class)]
    private Collection $programaciones;

    #[ORM\OneToMany(mappedBy: 'pantalla', targetEntity: ProgramacionEnergetica::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $programacionesEnergeticas;

    /**
     * Alertas dirigidas explícitamente a esta pantalla.
     * Las alertas globales (sin pantallas elegidas) no aparecen acá pero
     * igual se muestran en todas.
     *
     * @var Collection<int, AlertaEmergencia>
     */
    #[ORM\ManyToMany(targetEntity: AlertaEmergencia::class, mappedBy: 'pantallas')]
    private Collection $alertas;

    public function __construct()
    {
        $this->creadoEn                  = new DateTimeImmutable();
        $this->actualizadoEn             = new DateTimeImmutable();
        $this->programaciones            = new ArrayCollection();
        $this->programacionesEnergeticas = new ArrayCollection();
        $this->alertas                   = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->actualizadoEn = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): static { $this->nombre = $nombre; return $this; }

    public function getUbicacion(): Ubicacion { return $this->ubicacion; }
    public function setUbicacion(Ubicacion $ubicacion): static { $this->ubicacion = $ubicacion; return $this; }

    public function getReproductor(): ?Reproductor { return $this->reproductor; }
    public function setReproductor(?Reproductor $reproductor): static { $this->reproductor = $reproductor; return $this; }

    public function getPlaylistFallback(): ?Playlist { return $this->playlistFallback; }
    public function setPlaylistFallback(?Playlist $playlist): static { $this->playlistFallback = $playlist; return $this; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): static { $this->ipAddress = $ipAddress; return $this; }

    public function getMacAddress(): ?string { return $this->macAddress; }
    public function setMacAddress(?string $macAddress): static { $this->macAddress = $macAddress; return $this; }

    public function getResolucionAncho(): int { return $this->resolucionAncho; }
    public function setResolucionAncho(int $resolucionAncho): static { $this->resolucionAncho = $resolucionAncho; return $this; }

    public function getResolucionAlto(): int { return $this->resolucionAlto; }
    public function setResolucionAlto(int $resolucionAlto): static { $this->resolucionAlto = $resolucionAlto; return $this; }

    public function getEstado(): EstadoPantalla { return $this->estado; }
    public function setEstado(EstadoPantalla $estado): static { $this->estado = $estado; return $this; }

    public function getCreadoEn(): DateTimeImmutable { return $this->creadoEn; }
    public function getActualizadoEn(): DateTimeImmutable { return $this->actualizadoEn; }

    public function getProgramaciones(): Collection { return $this->programaciones; }
    public function getProgramacionesEnergeticas(): Collection { return $this->programacionesEnergeticas; }

    /** @return Collection<int, AlertaEmergencia> */
    public function getAlertas(): Collection { return $this->alertas; }
}
