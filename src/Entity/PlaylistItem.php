<?php

declare(strict_types=1);

namespace SPUI\Entity;

use Doctrine\ORM\Mapping as ORM;
use SPUI\Repository\PlaylistItemRepository;

#[ORM\Entity(repositoryClass: PlaylistItemRepository::class)]
#[ORM\Table(name: 'playlist_item')]
#[ORM\UniqueConstraint(name: 'uq_playlist_orden', columns: ['playlist_id', 'orden'])]
class PlaylistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Playlist::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private Playlist $playlist;

    #[ORM\ManyToOne(targetEntity: Contenido::class, inversedBy: 'playlistItems')]
    #[ORM\JoinColumn(nullable: false)]
    private Contenido $contenido;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $orden;

    public function getId(): ?int { return $this->id; }

    public function getPlaylist(): Playlist { return $this->playlist; }
    public function setPlaylist(Playlist $playlist): static { $this->playlist = $playlist; return $this; }

    public function getContenido(): Contenido { return $this->contenido; }
    public function setContenido(Contenido $contenido): static { $this->contenido = $contenido; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): static { $this->orden = $orden; return $this; }

    /**
     * Segundos que dura un contenido permanente cuando comparte la playlist con
     * otros: "permanente" dentro de una rotación dejaría a los demás sin verse.
     */
    public const DURACION_EN_ROTACION = 10;

    /**
     * Cuánto dura este ítem en pantalla, en segundos, o null si queda fijo.
     *
     * La duración la define el contenido y sólo el contenido: antes existía un
     * duracion_override_seg por ítem que permitía pisarla en cada playlist, y
     * eso dejaba dos fuentes de verdad para el mismo dato. Lo que sí depende de
     * la playlist es qué pasa con un contenido sin duración, y esa regla vive
     * acá y en ningún otro lado: es lo que recibe la Pi (SyncController) y lo
     * que muestra la ventana de ítems (etiquetaDuracion(); playlists/items.js
     * repite la misma regla para recalcular mientras se arma la lista).
     *
     *   - Video (subido o de YouTube): dura lo que dura. El subido, lo que dice
     *     su archivo (se lee al subirlo); el de YouTube, hasta que termina (la
     *     Pi lo descarga). Si es el único contenido de la playlist, null: se
     *     repite en bucle.
     *   - Con duración propia: esa duración.
     *   - Permanente y único contenido: null, queda fijo en pantalla.
     *   - Permanente en una playlist con otros: DURACION_EN_ROTACION.
     */
    public function getDuracionEfectiva(): ?int
    {
        $propia = $this->contenido->getDuracionSegundos();
        $unico  = $this->playlist->getItems()->count() <= 1;

        if ($this->contenido->esVideo()) {
            return $unico ? null : $propia;
        }
        if ($propia !== null) {
            return $propia;
        }

        return $unico ? null : self::DURACION_EN_ROTACION;
    }

    /** Lo que muestra la ventana de ítems: exactamente lo que va a pasar en pantalla. */
    public function etiquetaDuracion(): string
    {
        $segundos = $this->getDuracionEfectiva();
        if ($segundos !== null) {
            return Contenido::duracionLegible($segundos);
        }

        if ($this->contenido->esVideo()) {
            // Único video de la playlist, o uno cargado antes de que se leyera
            // la duración del archivo: en los dos casos se ve completo.
            return $this->playlist->getItems()->count() <= 1 ? 'En bucle' : 'Hasta que termine';
        }

        return 'Permanente';
    }
}
