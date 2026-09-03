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

    /**
     * URL que quedó codificada dentro del PNG que hay guardado.
     *
     * No es la URL destino: es el redirect del CMS con host y prefijo, o sea
     * la dirección por la que se llegaba al CMS cuando se generó la imagen. Se
     * guarda para poder detectar que el CMS se mudó (otra red, otro servidor)
     * y que los PNG quedaron apuntando a una dirección que ya no existe, sin
     * tener que abrir y decodificar cada archivo.
     *
     * NULL = generado antes de que esto existiera; se reconcilia solo la
     * primera vez que se entra a Contenidos.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $urlGenerada = null;

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

    /**
     * setUsosCount() en vez de un incrementarUsos() en memoria: el conteo se
     * incrementa siempre por CodigoQrRepository::incrementarUsosAtomico(), un
     * UPDATE SQL directo. Un ++ en memoria seguido de flush() es un
     * read-modify-write — bajo escaneos concurrentes reales dos requests leen
     * el mismo valor y el segundo flush() pisa el incremento del primero, sin
     * ningún error visible. Este setter existe sólo para que
     * incrementarUsosAtomico() pueda dejar la entidad ya cargada en memoria
     * en sync con lo que quedó en la base, sin otra lectura.
     */
    public function setUsosCount(int $usosCount): static { $this->usosCount = $usosCount; return $this; }

    public function getUrlGenerada(): ?string { return $this->urlGenerada; }
    public function setUrlGenerada(?string $urlGenerada): static { $this->urlGenerada = $urlGenerada; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): static { $this->activo = $activo; return $this; }

    public function getCreadoEn(): DateTimeImmutable { return $this->creadoEn; }

    public function estaVigente(): bool
    {
        return $this->activo && ($this->expiraEn === null || $this->expiraEn > new DateTimeImmutable());
    }
}
