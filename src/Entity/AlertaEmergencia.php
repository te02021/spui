<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\AlertaEmergenciaRepository;

#[ORM\Entity(repositoryClass: AlertaEmergenciaRepository::class)]
#[ORM\Table(name: 'alerta_emergencia')]
#[ORM\Index(name: 'idx_alerta_activa', columns: ['activa'])]
class AlertaEmergencia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $titulo;

    #[ORM\Column(type: 'text')]
    private string $mensaje;

    #[ORM\ManyToOne(targetEntity: Contenido::class, inversedBy: 'alertas')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Contenido $contenido = null;

    #[ORM\Column]
    private bool $activa = false;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $prioridad = 10;

    /** Referencia cross-DB a Intranet.user — sin FK Doctrine */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $creadoPorId;

    #[ORM\Column]
    private DateTimeImmutable $creadaEn;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $activadaEn = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $expiraEn = null;

    public function __construct()
    {
        $this->creadaEn = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitulo(): string { return $this->titulo; }
    public function setTitulo(string $titulo): static { $this->titulo = $titulo; return $this; }

    public function getMensaje(): string { return $this->mensaje; }
    public function setMensaje(string $mensaje): static { $this->mensaje = $mensaje; return $this; }

    public function getContenido(): ?Contenido { return $this->contenido; }
    public function setContenido(?Contenido $contenido): static { $this->contenido = $contenido; return $this; }

    public function isActiva(): bool { return $this->activa; }

    public function getPrioridad(): int { return $this->prioridad; }
    public function setPrioridad(int $prioridad): static { $this->prioridad = $prioridad; return $this; }

    public function getCreadoPorId(): int { return $this->creadoPorId; }
    public function setCreadoPorId(int $creadoPorId): static { $this->creadoPorId = $creadoPorId; return $this; }

    public function getCreadaEn(): DateTimeImmutable { return $this->creadaEn; }

    public function getActivadaEn(): ?DateTimeImmutable { return $this->activadaEn; }
    public function getExpiraEn(): ?DateTimeImmutable { return $this->expiraEn; }
    public function setExpiraEn(?DateTimeImmutable $expiraEn): static { $this->expiraEn = $expiraEn; return $this; }

    public function activar(): static
    {
        $this->activa = true;
        $this->activadaEn = new DateTimeImmutable();
        return $this;
    }

    public function desactivar(): static
    {
        $this->activa = false;
        return $this;
    }

    public function haExpirado(): bool
    {
        return $this->expiraEn !== null && $this->expiraEn < new DateTimeImmutable();
    }
}
