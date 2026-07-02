<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\CodigoQrRepository;

#[ORM\Entity(repositoryClass: CodigoQrRepository::class)]
#[ORM\Table(name: 'codigo_qr')]
class CodigoQr
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $urlDestino;

    #[ORM\Column(length: 200)]
    private string $etiqueta;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $expiraEn = null;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $usosCount = 0;

    #[ORM\Column]
    private bool $activo = true;

    #[ORM\Column]
    private DateTimeImmutable $creadoEn;

    public function __construct()
    {
        $this->creadoEn = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUrlDestino(): string { return $this->urlDestino; }
    public function setUrlDestino(string $urlDestino): static { $this->urlDestino = $urlDestino; return $this; }

    public function getEtiqueta(): string { return $this->etiqueta; }
    public function setEtiqueta(string $etiqueta): static { $this->etiqueta = $etiqueta; return $this; }

    public function getExpiraEn(): ?DateTimeImmutable { return $this->expiraEn; }
    public function setExpiraEn(?DateTimeImmutable $expiraEn): static { $this->expiraEn = $expiraEn; return $this; }

    public function getUsosCount(): int { return $this->usosCount; }
    public function incrementarUsos(): static { $this->usosCount++; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): static { $this->activo = $activo; return $this; }

    public function getCreadoEn(): DateTimeImmutable { return $this->creadoEn; }

    public function estaVigente(): bool
    {
        return $this->activo && ($this->expiraEn === null || $this->expiraEn > new DateTimeImmutable());
    }
}
