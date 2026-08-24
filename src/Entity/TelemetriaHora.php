<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\TelemetriaHoraRepository;

/**
 * Telemetría agregada por hora.
 *
 * Los reproductores publican una lectura por minuto, pero nada en el CMS
 * consume ese detalle: los gráficos del detalle de nodo agrupan por hora
 * (TelemetriaRepository::findSeriePorHora) y las tarjetas de resumen colapsan
 * todo el período en un solo valor. La única consulta que mira lecturas crudas
 * es la API REST que usa el propio Pi, y sólo pide las últimas.
 *
 * Guardar 90 días de datos por minuto para leerlos siempre agregados por hora
 * es 60 veces más de lo necesario. Con esta tabla el detalle crudo se retiene
 * pocos días y el histórico vive acá, a 1/60 del costo: con 50 reproductores,
 * ~12 MB por año en vez de ~700 MB por trimestre.
 *
 * Las columnas son exactamente las que ya devolvía findSeriePorHora(), para que
 * los templates no cambien.
 */
#[ORM\Entity(repositoryClass: TelemetriaHoraRepository::class)]
#[ORM\Table(name: 'telemetria_hora')]
#[ORM\UniqueConstraint(name: 'uq_reproductor_hora', columns: ['reproductor_id', 'hora'])]
class TelemetriaHora
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Reproductor::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reproductor $reproductor;

    /**
     * Inicio de la hora agregada, siempre con minutos y segundos en cero
     * (2026-08-15 14:00:00 cubre de 14:00:00 a 14:59:59).
     */
    #[ORM\Column]
    private DateTimeImmutable $hora;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $tempProm;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $tempMax;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $ramProm;

    /**
     * Se guarda aunque los gráficos no lo dibujen: la tarjeta de resumen del
     * detalle de nodo muestra el pico de RAM del período, y sin esta columna
     * no habría cómo calcularlo una vez purgado el crudo.
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $ramMax;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $discoMin;

    /**
     * Cuántas lecturas crudas se agregaron en esta hora. Con el intervalo por
     * defecto deberían ser ~60; un valor mucho menor delata que el equipo
     * estuvo caído parte de la hora, información que se perdería al purgar.
     */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $lecturas;

    public function getId(): ?string { return $this->id; }

    public function getReproductor(): Reproductor { return $this->reproductor; }
    public function setReproductor(Reproductor $reproductor): static { $this->reproductor = $reproductor; return $this; }

    public function getHora(): DateTimeImmutable { return $this->hora; }
    public function setHora(DateTimeImmutable $hora): static { $this->hora = $hora; return $this; }

    public function getTempProm(): float { return (float) $this->tempProm; }
    public function setTempProm(float $v): static { $this->tempProm = (string) $v; return $this; }

    public function getTempMax(): float { return (float) $this->tempMax; }
    public function setTempMax(float $v): static { $this->tempMax = (string) $v; return $this; }

    public function getRamProm(): float { return (float) $this->ramProm; }
    public function setRamProm(float $v): static { $this->ramProm = (string) $v; return $this; }

    public function getRamMax(): float { return (float) $this->ramMax; }
    public function setRamMax(float $v): static { $this->ramMax = (string) $v; return $this; }

    public function getDiscoMin(): int { return $this->discoMin; }
    public function setDiscoMin(int $discoMin): static { $this->discoMin = $discoMin; return $this; }

    public function getLecturas(): int { return $this->lecturas; }
    public function setLecturas(int $lecturas): static { $this->lecturas = $lecturas; return $this; }
}
