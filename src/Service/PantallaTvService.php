<?php

declare(strict_types=1);

namespace SPUI\Service;

use DateTimeImmutable;
use IntlDateFormatter;
use SPUI\Entity\Contenido;
use SPUI\Entity\CronogramaItem;
use SPUI\Entity\Edificio;
use SPUI\Enum\OrigenCronograma;
use SPUI\Enum\PlantillaPantalla;
use SPUI\Enum\TemaPantalla;
use SPUI\Enum\TipoContenido;
use SPUI\Repository\ContenidoRepository;
use SPUI\Repository\CronogramaItemRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lo que muestra el TV para un contenido (noviembre 2026): con qué plantilla y
 * tema, la fecha del encabezado y, en un cronograma, las actividades del día
 * repartidas en páginas.
 *
 * La vista previa del CMS (templates/pantalla/_tv.html.twig) se dibuja con
 * esto, así que lo que se ve en el panel sale de las mismas reglas que va a
 * usar la Pi.
 */
class PantallaTvService
{
    /** Actividades por página del cronograma (las que entran a 1080 px). */
    public const FILAS_POR_PAGINA = 7;

    public function __construct(
        private readonly CronogramaItemRepository $items,
        private readonly ContenidoRepository $contenidos,
        #[Autowire('%env(default:spui_tema_claro_desde_default:SPUI_TEMA_CLARO_DESDE)%')]
        private readonly string $claroDesde,
        #[Autowire('%env(default:spui_tema_oscuro_desde_default:SPUI_TEMA_OSCURO_DESDE)%')]
        private readonly string $oscuroDesde,
    ) {}

    /**
     * @param Edificio|null $edificio Para uno "del edificio de la pantalla": en
     *        qué edificio se lo mira (cada TV lo resuelve con el suyo).
     * @param list<CronogramaItem>|null $items Los ítems a mostrar, si no son
     *        los guardados (ninguno en un alta).
     * @return array<string, mixed>
     */
    public function vista(Contenido $c, ?Edificio $edificio = null, ?array $items = null, ?DateTimeImmutable $ahora = null): array
    {
        $ahora = $ahora ?? new DateTimeImmutable();
        $tipo  = $c->getTipo();
        // Un video va siempre sin plantilla (PlantillaPantalla::admiteTipo).
        $plantilla = $tipo === TipoContenido::Video ? PlantillaPantalla::Ninguna : $c->getPlantilla();

        $vista = [
            'tipo'       => $tipo->value,
            'titulo'     => $c->getTitulo(),
            'texto'      => $c->getContenidoTexto(),
            'plantilla'  => $plantilla->value,
            'tema'       => $this->temaEfectivo($c->getTema(), $ahora),
            'tema_auto'  => $c->getTema() === TemaPantalla::Automatico,
            'marca_agua' => $plantilla === PlantillaPantalla::Ninguna && $c->isMarcaAgua(),
            'fecha'      => $this->fechaLegible($ahora),
            'lugar'      => null,
        ];

        if ($tipo === TipoContenido::Cronograma) {
            $vista['cronograma'] = $this->cronograma($c, $edificio, $items, $ahora);
            $vista['lugar'] = $vista['cronograma']['edificio'];
        }

        return $vista;
    }

    /** 'clara' u 'oscura': el automático, según la hora (claro de 07:00 a 19:00). */
    public function temaEfectivo(TemaPantalla $tema, DateTimeImmutable $ahora): string
    {
        return match ($tema) {
            TemaPantalla::Claro  => 'clara',
            TemaPantalla::Oscuro => 'oscura',
            TemaPantalla::Automatico => ($ahora->format('H:i') >= $this->claroDesde && $ahora->format('H:i') < $this->oscuroDesde)
                ? 'clara' : 'oscura',
        };
    }

    /** "Lunes 2 de noviembre". */
    public function fechaLegible(DateTimeImmutable $dia): string
    {
        $f = new IntlDateFormatter('es_AR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, $dia->getTimezone(), null, "EEEE d 'de' MMMM");
        return mb_convert_case(mb_substr($f->format($dia), 0, 1), MB_CASE_UPPER) . mb_substr($f->format($dia), 1);
    }

    /**
     * Las actividades del día que todavía no terminaron (lo que ya pasó no
     * ocupa lugar), por hora de inicio y, a la misma hora, por aula en orden
     * natural (el 2 antes que el 19), repartidas en páginas.
     *
     * @param list<CronogramaItem>|null $items
     * @return array<string, mixed>
     */
    private function cronograma(Contenido $c, ?Edificio $edificio, ?array $items, DateTimeImmutable $ahora): array
    {
        $fuente = $c;
        if ($c->getCronogramaOrigen() === OrigenCronograma::Edificio) {
            // En cada TV se resuelve con el edificio de esa pantalla.
            $fuente = $edificio ? $this->contenidos->cronogramaVigenteDeEdificio($edificio, $ahora) : null;
            $items  = null;
        } else {
            $edificio = $c->getCronogramaEdificio();
        }

        $base = [
            'edificio'      => $edificio?->getNombre(),
            'segundos'      => $c->getSegundosPorPagina() ?? PresentacionContenidoService::SEG_PAGINA_DEFECTO,
            'paginas'       => [],
            'total'         => 0,
            // Por qué no hay nada que mostrar, en palabras del TV.
            'vacio'         => null,
        ];

        if ($fuente === null) {
            $base['vacio'] = $edificio
                ? 'Este edificio no tiene un cronograma vigente.'
                : 'Se muestra el cronograma del edificio de cada pantalla.';
            return $base;
        }
        if (!$fuente->vigenteEl($ahora)) {
            $base['vacio'] = 'El cronograma no está vigente hoy.';
            return $base;
        }

        $hora  = $ahora->format('H:i');
        $filas = [];
        foreach ($items ?? $this->items->findByContenidoOrdenado($fuente) as $it) {
            if (!$it->isActivo() || !$it->aplicaEl($ahora) || $it->getHoraFin()->format('H:i') <= $hora) {
                continue;
            }
            $inicio  = $it->getHoraInicio()->format('H:i');
            $filas[] = [
                'inicio'  => $inicio,
                'fin'     => $it->getHoraFin()->format('H:i'),
                'nombre'  => $it->getNombre(),
                'docente' => $it->getDocente(),
                'meta'    => implode(' · ', array_filter([$it->getCarrera(), $it->getAnio()])),
                'aula'    => $it->getAula(),
                'evento'  => $it->getTipo()->tieneFecha(),
                'curso'   => $inicio <= $hora,
            ];
        }
        usort($filas, fn($a, $b) => strcmp($a['inicio'], $b['inicio']) ?: strnatcasecmp((string) $a['aula'], (string) $b['aula']));

        $base['paginas'] = array_chunk($filas, self::FILAS_POR_PAGINA);
        $base['total']   = count($filas);
        if (!$filas) {
            $base['vacio'] = 'No quedan actividades por hoy.';
        }

        return $base;
    }
}
