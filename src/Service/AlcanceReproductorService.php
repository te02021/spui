<?php

declare(strict_types=1);

namespace SPUI\Service;

use SPUI\Entity\AlertaEmergencia;
use SPUI\Entity\Contenido;
use SPUI\Entity\Pantalla;
use SPUI\Entity\Playlist;
use SPUI\Entity\Programacion;
use SPUI\Entity\Reproductor;
use SPUI\Enum\EstadoConexion;
use SPUI\Repository\ReproductorRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resuelve qué reproductores afecta cada elemento del CMS.
 *
 * Sirve para deshabilitar en la interfaz las acciones dirigidas a equipos que
 * están offline: no tiene sentido dejar que el operador crea que configuró algo
 * si el destinatario no lo va a recibir mientras siga apagado.
 *
 * Ninguna de estas relaciones existía en el modelo: las entidades apuntan del
 * reproductor hacia afuera (un reproductor tiene pantallas), y acá hace falta
 * el sentido inverso. Por eso todo se resuelve navegando asociaciones.
 *
 * RENDIMIENTO: resolver esto por fila en un listado dispara la cadena completa
 * de consultas por cada elemento. Los controladores deben llamar a
 * idsOfflineDeUnaVez() una sola vez por request y pasarle ese conjunto a los
 * métodos afecta*(), en vez de consultar por elemento.
 */
final class AlcanceReproductorService
{
    public function __construct(
        private readonly ReproductorRepository $reproductorRepo,
        #[Autowire('%env(int:default:spui_umbral_conexion_default:SPUI_UMBRAL_CONEXION_SEG)%')]
        private readonly int $umbralConexionSeg = 150,
    ) {}

    /**
     * Ids de los reproductores que están offline en este momento.
     *
     * Una sola consulta para todo el request. El estado sale de
     * Reproductor::estadoCalculado(), no de la columna estado_conexion, que
     * puede estar desactualizada si el comando de mantenimiento no corrió.
     *
     * SinRegistrar NO cuenta como offline: un reproductor recién dado de alta
     * nunca se comunicó todavía, y bloquear acciones por él impediría la
     * configuración inicial del equipo — justo cuando hace falta configurarlo.
     *
     * @return array<int, true> ids como claves, para consultar con isset()
     */
    public function idsOfflineDeUnaVez(): array
    {
        $offline = [];

        foreach ($this->reproductorRepo->findAll() as $reproductor) {
            if ($reproductor->estadoCalculado($this->umbralConexionSeg) === EstadoConexion::Desconectado) {
                $offline[$reproductor->getId()] = true;
            }
        }

        return $offline;
    }

    /** Todos los reproductores dados de alta. Lo necesitan los alcances globales. */
    private function todos(): array
    {
        return $this->reproductorRepo->findAll();
    }

    // ── Resolución por entidad ───────────────────────────────────────────────

    /** @return Reproductor[] */
    public function dePantalla(Pantalla $pantalla): array
    {
        $r = $pantalla->getReproductor();

        // Una pantalla sin reproductor asignado no llega a ningún equipo.
        return $r !== null ? [$r] : [];
    }

    /**
     * @return Reproductor[]
     */
    public function deAlerta(AlertaEmergencia $alerta): array
    {
        // Alerta sin pantallas elegidas = global = va a todas las pantallas del
        // sistema, así que afecta a todos los reproductores.
        if ($alerta->esGlobal()) {
            return $this->todos();
        }

        $out = [];
        foreach ($alerta->getPantallas() as $pantalla) {
            $r = $pantalla->getReproductor();
            if ($r !== null) {
                $out[$r->getId()] = $r;   // por id: dos pantallas pueden compartir equipo
            }
        }

        return array_values($out);
    }

    /**
     * Una programación puede apuntar a una pantalla, a una ubicación completa,
     * a un edificio entero, o a nada (global). Los cuatro alcances están en
     * ProgramacionRepository::findVigentesParaPantalla(), que hace la consulta
     * en el sentido contrario.
     *
     * @return Reproductor[]
     */
    public function deProgramacion(Programacion $programacion): array
    {
        if ($programacion->getPantalla() !== null) {
            return $this->dePantalla($programacion->getPantalla());
        }

        if ($programacion->getUbicacion() !== null) {
            return $this->dePantallas($programacion->getUbicacion()->getPantallas());
        }

        if ($programacion->getEdificio() !== null) {
            $out = [];
            foreach ($programacion->getEdificio()->getUbicaciones() as $ubicacion) {
                foreach ($this->dePantallas($ubicacion->getPantallas()) as $r) {
                    $out[$r->getId()] = $r;
                }
            }
            return array_values($out);
        }

        // Sin ningún target: aplica a todas las pantallas del sistema.
        return $this->todos();
    }

    /**
     * Una playlist llega a un reproductor por dos caminos: como playlist de una
     * programación, o como playlist de respaldo de una pantalla.
     *
     * @return Reproductor[]
     */
    public function dePlaylist(Playlist $playlist): array
    {
        $out = [];

        foreach ($playlist->getProgramaciones() as $programacion) {
            foreach ($this->deProgramacion($programacion) as $r) {
                $out[$r->getId()] = $r;
            }
        }

        // Pantallas que la usan como fallback. No hay relación inversa desde
        // Playlist, así que hay que recorrer los reproductores y sus pantallas.
        foreach ($this->todos() as $reproductor) {
            foreach ($reproductor->getPantallas() as $pantalla) {
                if ($pantalla->getPlaylistFallback()?->getId() === $playlist->getId()) {
                    $out[$reproductor->getId()] = $reproductor;
                }
            }
        }

        return array_values($out);
    }

    /**
     * Un contenido llega a los reproductores a través de las playlists que lo
     * incluyen.
     *
     * @return Reproductor[]
     */
    public function deContenido(Contenido $contenido): array
    {
        $out = [];

        foreach ($contenido->getPlaylistItems() as $item) {
            foreach ($this->dePlaylist($item->getPlaylist()) as $r) {
                $out[$r->getId()] = $r;
            }
        }

        return array_values($out);
    }

    // ── Consultas de estado ──────────────────────────────────────────────────

    /**
     * @param  Reproductor[]     $reproductores
     * @param  array<int, true>  $idsOffline  Resultado de idsOfflineDeUnaVez()
     */
    public function hayAlgunoOffline(array $reproductores, array $idsOffline): bool
    {
        foreach ($reproductores as $r) {
            if (isset($idsOffline[$r->getId()])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Texto para el tooltip del botón deshabilitado.
     *
     * Nombra los equipos concretos y hace cuánto están caídos: "hay
     * reproductores offline" no le dice al operador cuál revisar.
     *
     * @param  Reproductor[]    $reproductores
     * @param  array<int, true> $idsOffline
     */
    public function motivoBloqueo(array $reproductores, array $idsOffline): ?string
    {
        $caidos = array_filter($reproductores, fn(Reproductor $r) => isset($idsOffline[$r->getId()]));

        if ($caidos === []) {
            return null;
        }

        $detalles = [];
        foreach ($caidos as $r) {
            $seg = $r->segundosDesdeHeartbeat();
            $detalles[] = $seg === null
                ? $r->getHostname()
                : sprintf('%s (hace %s)', $r->getHostname(), $this->humanizar($seg));
        }

        // Con muchos equipos el tooltip sería ilegible: se nombran los primeros
        // y se resume el resto.
        if (count($detalles) > 3) {
            $extra    = count($detalles) - 3;
            $detalles = array_slice($detalles, 0, 3);
            $detalles[] = sprintf('y %d más', $extra);
        }

        return sprintf(
            'Desconectado: %s. La acción estará disponible cuando el reproductor vuelva.',
            implode(', ', $detalles),
        );
    }

    /**
     * Motivo de bloqueo de UN reproductor concreto, para usar desde Twig.
     *
     * Atajo del caso más común en los listados: la fila muestra un reproductor
     * y sus acciones. Evita tener que armar el array y pasar idsOffline en cada
     * template.
     */
    public function motivoDe(Reproductor $reproductor, array $idsOffline): ?string
    {
        return $this->motivoBloqueo([$reproductor], $idsOffline);
    }

    /**
     * Motivo de bloqueo de cualquier entidad del CMS, para usar desde Twig.
     *
     * Un solo punto de entrada en vez de un método por tipo: los templates
     * llaman siempre igual y no tienen que saber cuál corresponde a cada
     * listado.
     *
     * @param array<int, true> $idsOffline Resultado de idsOfflineDeUnaVez().
     */
    public function motivoParaEntidad(object $entidad, array $idsOffline): ?string
    {
        $reproductores = match (true) {
            $entidad instanceof Reproductor      => [$entidad],
            $entidad instanceof Pantalla         => $this->dePantalla($entidad),
            $entidad instanceof AlertaEmergencia => $this->deAlerta($entidad),
            $entidad instanceof Programacion     => $this->deProgramacion($entidad),
            $entidad instanceof Playlist         => $this->dePlaylist($entidad),
            $entidad instanceof Contenido        => $this->deContenido($entidad),
            // Entidad sin relación con reproductores (edificios, ubicaciones,
            // códigos QR): nada que bloquear.
            default                              => [],
        };

        return $this->motivoBloqueo($reproductores, $idsOffline);
    }

    /**
     * Motivo por el que hay que bloquear la acción, o null si puede seguir.
     *
     * Es el chequeo que usan los controladores antes de escribir. Deshabilitar
     * el botón en la interfaz no alcanza: la ruta sigue siendo alcanzable con
     * curl, y basta con que un reproductor se caiga entre que se renderizó la
     * página y el usuario hizo clic para que la acción pase igual.
     *
     * Devuelve el texto y no una Response para que cada controlador la arme
     * como corresponde a su flujo (JSON para modales, flash + redirect para
     * navegación normal), que es el patrón que ya usan todos.
     *
     * @param Reproductor[] $reproductores Los que afecta la acción.
     */
    public function bloqueoPara(array $reproductores): ?string
    {
        $idsOffline = $this->idsOfflineDeUnaVez();

        if (!$this->hayAlgunoOffline($reproductores, $idsOffline)) {
            return null;
        }

        return $this->motivoBloqueo($reproductores, $idsOffline)
            ?? 'Hay reproductores desconectados.';
    }

    private function humanizar(int $segundos): string
    {
        if ($segundos < 90)    return 'instantes';
        if ($segundos < 3600)  return sprintf('%d min', (int) round($segundos / 60));
        if ($segundos < 86400) return sprintf('%d h', (int) round($segundos / 3600));

        return sprintf('%d días', (int) round($segundos / 86400));
    }

    /**
     * @param  iterable<Pantalla> $pantallas
     * @return Reproductor[]
     */
    private function dePantallas(iterable $pantallas): array
    {
        $out = [];
        foreach ($pantallas as $pantalla) {
            $r = $pantalla->getReproductor();
            if ($r !== null) {
                $out[$r->getId()] = $r;
            }
        }

        return array_values($out);
    }
}
