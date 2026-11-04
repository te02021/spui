<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Enum\EstadoContenido;
use SPUI\Enum\OrigenCronograma;
use SPUI\Enum\PlantillaPantalla;
use SPUI\Enum\TemaPantalla;
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

    /**
     * Código QR asociado, sólo para contenidos de tipo `qr`.
     *
     * El PNG que se muestra en pantalla se genera al guardar y se guarda como
     * archivo (rutaArchivo), igual que una imagen, así el Pi lo descarga y lo
     * cachea con la tubería de medios de siempre. Ese PNG codifica la URL de
     * redirect del CMS, no la URL destino: por eso los escaneos se cuentan.
     */
    #[ORM\ManyToOne(targetEntity: CodigoQr::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CodigoQr $codigoQr = null;

    #[ORM\Column(enumType: EstadoContenido::class)]
    private EstadoContenido $estado = EstadoContenido::Disponible;

    // ── Presentación en el TV (noviembre 2026) ─────────────────────────────
    // Al crear un contenido el CMS pone la plantilla que corresponde a su
    // tipo (PlantillaPantalla::porDefecto); este default sólo cubre la tabla.

    #[ORM\Column(length: 20, enumType: PlantillaPantalla::class, options: ['default' => 'institucional'])]
    private PlantillaPantalla $plantilla = PlantillaPantalla::Institucional;

    #[ORM\Column(length: 20, enumType: TemaPantalla::class, options: ['default' => 'automatico'])]
    private TemaPantalla $tema = TemaPantalla::Automatico;

    /** El molino chico en una esquina. Sólo se usa sin plantilla: la institucional ya lleva el logo. */
    #[ORM\Column(options: ['default' => false])]
    private bool $marcaAgua = false;

    // ── Sólo cronogramas ───────────────────────────────────────────────────

    #[ORM\Column(length: 20, nullable: true, enumType: OrigenCronograma::class)]
    private ?OrigenCronograma $cronogramaOrigen = null;

    /**
     * A qué edificio pertenece un cronograma propio. Opcional: hace falta para
     * que lo encuentren los cronogramas "del edificio de la pantalla".
     */
    #[ORM\ManyToOne(targetEntity: Edificio::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Edificio $cronogramaEdificio = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $vigenciaDesde = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $vigenciaHasta = null;

    /** Cuánto queda cada página de la tabla antes de deslizarse a la siguiente. */
    #[ORM\Column(type: 'smallint', nullable: true, options: ['unsigned' => true])]
    private ?int $segundosPorPagina = null;

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

    /** "45 s", "1 min 12 s", "2 min". La misma forma que usa playlists/items.js. */
    public static function duracionLegible(int $segundos): string
    {
        if ($segundos < 60) {
            return $segundos . ' s';
        }
        $min = intdiv($segundos, 60);
        $seg = $segundos % 60;
        return $min . ' min' . ($seg ? ' ' . $seg . ' s' : '');
    }

    /** La duración propia del contenido, para listados y para el catálogo de la playlist. */
    public function etiquetaDuracion(): string
    {
        // Un cronograma dura lo que tardan en pasar sus páginas, y eso lo
        // sabe la Pi (depende de cuántas actividades quedan en el día).
        if ($this->esCronograma()) {
            return 'Según sus páginas';
        }
        if ($this->duracionSegundos !== null) {
            return self::duracionLegible($this->duracionSegundos);
        }
        // Un video sin duración es uno subido antes de que se leyera del
        // archivo: igual se muestra completo.
        return $this->esVideo() ? 'Hasta que termine' : 'Permanente';
    }

    /** Un video dura lo que dura su archivo: la duración no se escribe. */
    public function esVideo(): bool
    {
        return $this->tipo === TipoContenido::Video;
    }

    public function esCronograma(): bool
    {
        return $this->tipo === TipoContenido::Cronograma;
    }

    /** Un cronograma con clases cargadas (no uno "del edificio de la pantalla"). */
    public function tieneItemsPropios(): bool
    {
        return $this->esCronograma() && $this->cronogramaOrigen !== OrigenCronograma::Edificio;
    }

    public function getPlantilla(): PlantillaPantalla { return $this->plantilla; }
    public function setPlantilla(PlantillaPantalla $plantilla): static { $this->plantilla = $plantilla; return $this; }

    public function getTema(): TemaPantalla { return $this->tema; }
    public function setTema(TemaPantalla $tema): static { $this->tema = $tema; return $this; }

    public function isMarcaAgua(): bool { return $this->marcaAgua; }
    public function setMarcaAgua(bool $marcaAgua): static { $this->marcaAgua = $marcaAgua; return $this; }

    public function getCronogramaOrigen(): ?OrigenCronograma { return $this->cronogramaOrigen; }
    public function setCronogramaOrigen(?OrigenCronograma $origen): static { $this->cronogramaOrigen = $origen; return $this; }

    public function getCronogramaEdificio(): ?Edificio { return $this->cronogramaEdificio; }
    public function setCronogramaEdificio(?Edificio $edificio): static { $this->cronogramaEdificio = $edificio; return $this; }

    public function getVigenciaDesde(): ?DateTimeImmutable { return $this->vigenciaDesde; }
    public function setVigenciaDesde(?DateTimeImmutable $d): static { $this->vigenciaDesde = $d; return $this; }

    public function getVigenciaHasta(): ?DateTimeImmutable { return $this->vigenciaHasta; }
    public function setVigenciaHasta(?DateTimeImmutable $d): static { $this->vigenciaHasta = $d; return $this; }

    public function getSegundosPorPagina(): ?int { return $this->segundosPorPagina; }
    public function setSegundosPorPagina(?int $s): static { $this->segundosPorPagina = $s; return $this; }

    /** Si el cronograma rige en esa fecha (sin vigencia cargada, siempre). */
    public function vigenteEl(DateTimeImmutable $dia): bool
    {
        $d = $dia->format('Y-m-d');
        return ($this->vigenciaDesde === null || $this->vigenciaDesde->format('Y-m-d') <= $d)
            && ($this->vigenciaHasta === null || $this->vigenciaHasta->format('Y-m-d') >= $d);
    }

    public function getHashArchivo(): ?string { return $this->hashArchivo; }
    public function setHashArchivo(?string $hashArchivo): static { $this->hashArchivo = $hashArchivo; return $this; }

    public function getCodigoQr(): ?CodigoQr { return $this->codigoQr; }
    public function setCodigoQr(?CodigoQr $codigoQr): static { $this->codigoQr = $codigoQr; return $this; }

    public function getEstado(): EstadoContenido { return $this->estado; }
    public function setEstado(EstadoContenido $estado): static { $this->estado = $estado; return $this; }

    public function estaArchivado(): bool { return $this->estado === EstadoContenido::Archivado; }

    /** En cuántas playlists distintas está (para avisar antes de archivarlo). */
    public function cantidadPlaylists(): int
    {
        $ids = [];
        foreach ($this->playlistItems as $item) {
            $ids[$item->getPlaylist()->getId()] = true;
        }
        return count($ids);
    }

    public function getCreadoPorId(): int { return $this->creadoPorId; }
    public function setCreadoPorId(int $creadoPorId): static { $this->creadoPorId = $creadoPorId; return $this; }

    public function getCreadoEn(): DateTimeImmutable { return $this->creadoEn; }
    public function getActualizadoEn(): DateTimeImmutable { return $this->actualizadoEn; }

    public function getPlaylistItems(): Collection { return $this->playlistItems; }
    public function getAlertas(): Collection { return $this->alertas; }
}
