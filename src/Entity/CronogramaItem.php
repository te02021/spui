<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Enum\TipoItemCronograma;
use SPUI\Repository\CronogramaItemRepository;

#[ORM\Entity(repositoryClass: CronogramaItemRepository::class)]
#[ORM\Table(name: 'cronograma_item')]
class CronogramaItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contenido::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contenido $contenido;

    #[ORM\Column(length: 200)]
    private ?string $nombre = null;

    /** Aula o espacio físico donde ocurre el evento — Ej: "Aula 3", "Lab 2", "SUM" */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $aula = null;

    #[ORM\Column(type: 'time_immutable')]
    private ?DateTimeImmutable $horaInicio = null;

    #[ORM\Column(type: 'time_immutable')]
    private ?DateTimeImmutable $horaFin = null;

    /**
     * Bitmask: bit0=Lun…bit6=Dom — igual que Programacion.diasSemana. Un
     * cronograma se carga una vez por semana (como el Excel): cada clase
     * lleva los días en que se dicta. No se usa en un evento especial.
     */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $diasSemana = 127;

    // ── Noviembre 2026: los datos del Excel de horarios ────────────────────

    #[ORM\Column(length: 20, enumType: TipoItemCronograma::class, options: ['default' => 'clase'])]
    private TipoItemCronograma $tipo = TipoItemCronograma::Clase;

    /** Sólo un evento especial: el día en que ocurre (en vez de días de la semana). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $fecha = null;

    /** Sigla de la carrera, tal como viene en el Excel ("AGI", "AGI/GT"). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $carrera = null;

    /** Año de cursado ya normalizado ("1.º", "2.º y 4.º"). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $anio = null;

    /** Docentes, o el responsable de un evento ("Ariado, L. / Bianchini, C."). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $docente = null;

    #[ORM\Column]
    private bool $activo = true;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $orden = 0;

    public function getId(): ?int { return $this->id; }

    public function getContenido(): Contenido { return $this->contenido; }
    public function setContenido(Contenido $contenido): static { $this->contenido = $contenido; return $this; }

    /**
     * Campos obligatorios con setter nullable: el formulario mapea al objeto
     * ANTES de validar, así que un campo vacío escribía null en un setter
     * tipado y reventaba con TypeError antes de que corrieran los NotBlank
     * (el modal quedaba sin mostrar ningún error). La obligatoriedad la
     * imponen los NotBlank del FormType y las columnas NOT NULL de la tabla.
     */
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): static { $this->nombre = $nombre; return $this; }

    public function getAula(): ?string { return $this->aula; }
    public function setAula(?string $aula): static { $this->aula = $aula; return $this; }

    public function getHoraInicio(): ?DateTimeImmutable { return $this->horaInicio; }
    public function setHoraInicio(?DateTimeImmutable $h): static { $this->horaInicio = $h; return $this; }

    public function getHoraFin(): ?DateTimeImmutable { return $this->horaFin; }
    public function setHoraFin(?DateTimeImmutable $h): static { $this->horaFin = $h; return $this; }

    public function getDiasSemana(): int { return $this->diasSemana; }
    public function setDiasSemana(int $d): static { $this->diasSemana = $d; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): static { $this->activo = $activo; return $this; }

    public function getOrden(): int { return $this->orden; }
    // null = el campo llegó vacío del formulario: se ignora y queda el valor
    // anterior; el NotBlank del FormType es el que avisa "obligatorio".
    public function setOrden(?int $orden): static { if ($orden !== null) { $this->orden = $orden; } return $this; }

    public function getTipo(): TipoItemCronograma { return $this->tipo; }
    public function setTipo(TipoItemCronograma $tipo): static { $this->tipo = $tipo; return $this; }

    public function getFecha(): ?DateTimeImmutable { return $this->fecha; }
    public function setFecha(?DateTimeImmutable $fecha): static { $this->fecha = $fecha; return $this; }

    public function getCarrera(): ?string { return $this->carrera; }
    public function setCarrera(?string $carrera): static { $this->carrera = $carrera; return $this; }

    public function getAnio(): ?string { return $this->anio; }
    public function setAnio(?string $anio): static { $this->anio = $anio; return $this; }

    public function getDocente(): ?string { return $this->docente; }
    public function setDocente(?string $docente): static { $this->docente = $docente; return $this; }

    public function aplicaHoy(): bool
    {
        return $this->aplicaEl(new DateTimeImmutable());
    }

    /** Un evento, en su fecha; una clase o actividad, en sus días de la semana. */
    public function aplicaEl(DateTimeImmutable $dia): bool
    {
        if ($this->tipo->tieneFecha()) {
            return $this->fecha !== null && $this->fecha->format('Y-m-d') === $dia->format('Y-m-d');
        }
        $bit = (int) $dia->format('N') - 1;
        return (bool)($this->diasSemana & (1 << $bit));
    }
}
