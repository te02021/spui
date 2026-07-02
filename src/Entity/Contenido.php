<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Enum\EstadoContenido;
use SPUI\Enum\TipoContenido;
use SPUI\Repository\ContenidoRepository;

#[ORM\Entity(repositoryClass: ContenidoRepository::class)]
#[ORM\Table(name: 'contenido')]
#[ORM\HasLifecycleCallbacks]
class Contenido
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $titulo;

    #[ORM\Column(enumType: TipoContenido::class)]
    private TipoContenido $tipo;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $rutaArchivo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contenidoTexto = null;

    /** Duración en segundos. null = mostrar permanentemente (contenido fijo o cronograma con auto-scroll) */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true], nullable: true)]
    private ?int $duracionSegundos = null;

    /** SHA-256 del archivo para verificar integridad al descargar en el Pi */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hashArchivo = null;

    #[ORM\Column(enumType: EstadoContenido::class)]
    private EstadoContenido $estado = EstadoContenido::Borrador;

    /** Referencia cross-DB a Intranet.user — sin FK Doctrine */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $creadoPorId;

    #[ORM\Column]
    private DateTimeImmutable $creadoEn;

    #[ORM\Column]
    private DateTimeImmutable $actualizadoEn;

    #[ORM\OneToMany(mappedBy: 'contenido', targetEntity: PlaylistItem::class)]
    private Collection $playlistItems;

    #[ORM\OneToMany(mappedBy: 'contenido', targetEntity: AlertaEmergencia::class)]
    private Collection $alertas;

    public function __construct()
    {
        $this->creadoEn      = new DateTimeImmutable();
        $this->actualizadoEn = new DateTimeImmutable();
        $this->playlistItems = new ArrayCollection();
        $this->alertas       = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->actualizadoEn = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitulo(): string { return $this->titulo; }
    public function setTitulo(string $titulo): static { $this->titulo = $titulo; return $this; }

    public function getTipo(): TipoContenido { return $this->tipo; }
    public function setTipo(TipoContenido $tipo): static { $this->tipo = $tipo; return $this; }

    public function getRutaArchivo(): ?string { return $this->rutaArchivo; }
    public function setRutaArchivo(?string $rutaArchivo): static { $this->rutaArchivo = $rutaArchivo; return $this; }

    public function getContenidoTexto(): ?string { return $this->contenidoTexto; }
    public function setContenidoTexto(?string $contenidoTexto): static { $this->contenidoTexto = $contenidoTexto; return $this; }

    public function getDuracionSegundos(): ?int { return $this->duracionSegundos; }
    public function setDuracionSegundos(?int $duracionSegundos): static { $this->duracionSegundos = $duracionSegundos; return $this; }

    public function getHashArchivo(): ?string { return $this->hashArchivo; }
    public function setHashArchivo(?string $hashArchivo): static { $this->hashArchivo = $hashArchivo; return $this; }

    public function getEstado(): EstadoContenido { return $this->estado; }
    public function setEstado(EstadoContenido $estado): static { $this->estado = $estado; return $this; }

    public function getCreadoPorId(): int { return $this->creadoPorId; }
    public function setCreadoPorId(int $creadoPorId): static { $this->creadoPorId = $creadoPorId; return $this; }

    public function getCreadoEn(): DateTimeImmutable { return $this->creadoEn; }
    public function getActualizadoEn(): DateTimeImmutable { return $this->actualizadoEn; }

    public function getPlaylistItems(): Collection { return $this->playlistItems; }
    public function getAlertas(): Collection { return $this->alertas; }
}
