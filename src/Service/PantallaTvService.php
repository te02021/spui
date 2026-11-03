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
 * que quedan (se reparten en páginas al dibujar, según lo que ocupa cada una).
 *
 * La vista previa del CMS (templates/pantalla/_tv.html.twig) se dibuja con
 * esto, así que lo que se ve en el panel sale de las mismas reglas que va a
 * usar la Pi.
 */
class PantallaTvService
{
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
            // La hora en vivo (03/11/2026): la pone el servidor y vista-tv.js
            // la mantiene al día cada minuto, como la Pi.
            'hora'       => $ahora->format('H:i'),
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
     * natural (el 2 antes que el 19).
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
            'filas'         => [],
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
            // Sin hora de fin (un evento "desde las 18:00"): sigue hasta que
            // termina el día.
            $fin = $it->getHoraFin()?->format('H:i');
            if (!$it->isActivo() || !$it->aplicaEl($ahora) || ($fin !== null && $fin <= $hora)) {
                continue;
            }
            $fila = $this->fila($it);
            $fila['curso'] = $fila['inicio'] <= $hora;
            $filas[] = $fila;
        }
        usort($filas, fn($a, $b) => strcmp($a['inicio'], $b['inicio']) ?: strnatcasecmp((string) $a['aula'], (string) $b['aula']));

        // Las páginas se arman al dibujar, por altura: una materia larga ocupa
        // más de un renglón (nunca se corta ni se achica la letra), así que
        // no entra siempre la misma cantidad de filas.
        $base['filas'] = $filas;
        $base['total'] = count($filas);
        if (!$filas) {
            $base['vacio'] = 'No quedan actividades por hoy.';
        }

        return $base;
    }

    /** Una actividad tal como la dibuja el TV (la vista previa y la Pi). */
    private function fila(CronogramaItem $it): array
    {
        return [
            'inicio'  => $it->getHoraInicio()->format('H:i'),
            // Sin hora de fin: un evento "desde las 18:00".
            'fin'     => $it->getHoraFin()?->format('H:i'),
            'nombre'  => $it->getNombre(),
            'docente' => $it->getDocente(),
            'meta'    => implode(' · ', array_filter([$it->getCarrera(), $it->getAnio()])),
            'aula'    => $it->getAula(),
            'evento'  => $it->getTipo()->tieneFecha(),
        ];
    }

    /** Desde qué hora el tema automático es claro y desde cuál oscuro, para la Pi. */
    public function horarioTema(): array
    {
        return ['claro_desde' => $this->claroDesde, 'oscuro_desde' => $this->oscuroDesde];
    }

    /**
     * El cronograma que baja al reproductor (noviembre 2026): la semana
     * entera y no sólo lo de hoy, para que la Pi elija el día y la hora por
     * su cuenta. Así pasa sola de un día al otro, y sin conexión con el CMS
     * sigue mostrando lo que corresponde con lo que quedó en su caché.
     *
     * Van las clases (con sus días de la semana, la misma máscara que
     * CronogramaItem) y los eventos de hoy a una semana. La Pi aplica las
     * mismas reglas que cronograma() de arriba: vigencia, día, lo que ya
     * terminó no ocupa lugar y el orden por hora y aula.
     *
     * @param Edificio|null $edificio El de la pantalla, para uno "del
     *        edificio de la pantalla".
     * @return array<string, mixed>
     */
    public function cronogramaParaReproductor(Contenido $c, ?Edificio $edificio, DateTimeImmutable $ahora): array
    {
        $fuente = $c;
        if ($c->getCronogramaOrigen() === OrigenCronograma::Edificio) {
            $fuente = $edificio ? $this->contenidos->cronogramaVigenteDeEdificio($edificio, $ahora) : null;
        } else {
            $edificio = $c->getCronogramaEdificio();
        }

        $datos = [
            'edificio'            => $edificio?->getNombre(),
            'segundos_por_pagina' => $c->getSegundosPorPagina() ?? PresentacionContenidoService::SEG_PAGINA_DEFECTO,
            'vigencia_desde'      => $fuente?->getVigenciaDesde()?->format('Y-m-d'),
            'vigencia_hasta'      => $fuente?->getVigenciaHasta()?->format('Y-m-d'),
            'items'               => [],
            // Cuando no hay de dónde sacar actividades, lo que dice el TV.
            'vacio'               => $fuente === null ? 'Este edificio no tiene un cronograma vigente.' : null,
        ];
        if ($fuente === null) {
            return $datos;
        }

        $hoy   = $ahora->format('Y-m-d');
        $hasta = $ahora->modify('+7 days')->format('Y-m-d');
        foreach ($this->items->findByContenidoOrdenado($fuente) as $it) {
            $fecha = $it->getTipo()->tieneFecha() ? $it->getFecha()?->format('Y-m-d') : null;
            // Un evento ya pasado, o más allá de la semana, no hace falta.
            if ($it->getTipo()->tieneFecha() && ($fecha === null || $fecha < $hoy || $fecha > $hasta)) {
                continue;
            }
            $datos['items'][] = ['dias' => $it->getDiasSemana(), 'fecha' => $fecha] + $this->fila($it);
        }

        return $datos;
    }
}
