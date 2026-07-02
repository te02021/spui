<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Enum\EstadoConexion;
use SPUI\Repository\ReproductorRepository;

#[ORM\Entity(repositoryClass: ReproductorRepository::class)]
#[ORM\Table(name: 'reproductor')]
class Reproductor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $hostname;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $versionFirmware = null;

    /** SHA-256 de la API key generada — nunca se almacena la key en claro */
    #[ORM\Column(length: 64)]
    private string $apiKeyHash;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $ultimoHeartbeat = null;

    #[ORM\Column(enumType: EstadoConexion::class)]
    private EstadoConexion $estadoConexion = EstadoConexion::SinRegistrar;

    #[ORM\OneToMany(mappedBy: 'reproductor', targetEntity: Pantalla::class)]
    private Collection $pantallas;

    #[ORM\OneToMany(mappedBy: 'reproductor', targetEntity: Telemetria::class)]
    private Collection $telemetrias;

    public function __construct()
    {
        $this->pantallas   = new ArrayCollection();
        $this->telemetrias = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getHostname(): string { return $this->hostname; }
    public function setHostname(string $hostname): static { $this->hostname = $hostname; return $this; }

    public function getVersionFirmware(): ?string { return $this->versionFirmware; }
    public function setVersionFirmware(?string $v): static { $this->versionFirmware = $v; return $this; }

    public function getApiKeyHash(): string { return $this->apiKeyHash; }
    public function setApiKeyHash(string $hash): static { $this->apiKeyHash = $hash; return $this; }

    public function getUltimoHeartbeat(): ?DateTimeImmutable { return $this->ultimoHeartbeat; }

    public function registrarHeartbeat(): static
    {
        $this->ultimoHeartbeat = new DateTimeImmutable();
        $this->estadoConexion  = EstadoConexion::Conectado;
        return $this;
    }

    public function getEstadoConexion(): EstadoConexion { return $this->estadoConexion; }
    public function setEstadoConexion(EstadoConexion $e): static { $this->estadoConexion = $e; return $this; }

    public function getPantallas(): Collection { return $this->pantallas; }
    public function getTelemetrias(): Collection { return $this->telemetrias; }

    public function verificarApiKey(string $rawKey): bool
    {
        return hash('sha256', $rawKey) === $this->apiKeyHash;
    }

    public function __toString(): string { return $this->hostname; }
}
