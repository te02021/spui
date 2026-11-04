<?php

declare(strict_types=1);

namespace SPUI\Service;

use DateTimeImmutable;
use SPUI\Entity\Contenido;
use SPUI\Entity\Edificio;
use SPUI\Enum\OrigenCronograma;
use SPUI\Enum\PlantillaPantalla;
use SPUI\Enum\TemaPantalla;
use SPUI\Enum\TipoContenido;
use SPUI\Repository\EdificioRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Cómo se presenta un contenido en el TV (noviembre 2026): plantilla, tema,
 * marca de agua y, en un cronograma, origen, edificio, vigencia y segundos por
 * página.
 *
 * Son campos manuales, como los del QR, y los comparten el alta y la edición
 * (templates/contenidos/_presentacion.html.twig): acá se leen y validan una
 * sola vez para los dos.
 */
class PresentacionContenidoService
{
    public const SEG_PAGINA_DEFECTO = 10;
    public const SEG_PAGINA_MIN     = 5;
    public const SEG_PAGINA_MAX     = 60;

    /** Lo dicen el servidor y validacion-vivo.js mientras se elige la fecha. */
    public const MSG_VIGENCIA = 'La vigencia tiene que terminar el mismo día o después de empezar.';

    public function __construct(private readonly EdificioRepository $edificios) {}

    /**
     * Pasa al contenido lo que llegó en el formulario. El tipo ya tiene que
     * estar puesto.
     *
     * @return array{0:string,1:string}|null [name del campo, mensaje] si algo no es válido.
     */
    public function aplicar(Contenido $c, Request $request): ?array
    {
        $tipo = $c->getTipo();
        $p    = $request->request;

        $plantilla = PlantillaPantalla::tryFrom($p->getString('plantilla')) ?? PlantillaPantalla::porDefecto($tipo);
        if (!PlantillaPantalla::admiteTipo($plantilla, $tipo)) {
            $plantilla = PlantillaPantalla::Ninguna;
        }
        $c->setPlantilla($plantilla);
        $c->setTema(TemaPantalla::tryFrom($p->getString('tema')) ?? TemaPantalla::Automatico);
        // La institucional ya lleva el logo: la marca de agua sólo va sin plantilla.
        $c->setMarcaAgua($plantilla === PlantillaPantalla::Ninguna && $p->getBoolean('marca_agua'));

        if ($tipo !== TipoContenido::Cronograma) {
            return null;
        }

        $origen = OrigenCronograma::tryFrom($p->getString('cronograma_origen')) ?? OrigenCronograma::Propio;
        $c->setCronogramaOrigen($origen);
        // Su duración la da la cantidad de páginas: la calcula la Pi.
        $c->setDuracionSegundos(null);

        $seg = trim($p->getString('segundos_por_pagina'));
        if ($seg === '') {
            $c->setSegundosPorPagina(self::SEG_PAGINA_DEFECTO);
        } elseif (!ctype_digit($seg) || (int) $seg < self::SEG_PAGINA_MIN || (int) $seg > self::SEG_PAGINA_MAX) {
            return ['segundos_por_pagina', sprintf('Elegí entre %d y %d segundos.', self::SEG_PAGINA_MIN, self::SEG_PAGINA_MAX)];
        } else {
            $c->setSegundosPorPagina((int) $seg);
        }

        // Uno "del edificio de la pantalla" no tiene edificio ni vigencia
        // propios: toma los del cronograma que encuentre en cada TV.
        if ($origen === OrigenCronograma::Edificio) {
            $c->setCronogramaEdificio(null)->setVigenciaDesde(null)->setVigenciaHasta(null);
            return null;
        }

        $edificioId = $p->getString('cronograma_edificio');
        $edificio   = $edificioId !== '' ? $this->edificios->find((int) $edificioId) : null;
        if ($edificioId !== '' && !$edificio) {
            return ['cronograma_edificio', 'Ese edificio ya no existe. Elegí otro.'];
        }
        $c->setCronogramaEdificio($edificio);

        $desde = $this->fecha($p->getString('vigencia_desde'));
        $hasta = $this->fecha($p->getString('vigencia_hasta'));
        if ($desde === false) {
            return ['vigencia_desde', 'La fecha no es válida.'];
        }
        if ($hasta === false) {
            return ['vigencia_hasta', 'La fecha no es válida.'];
        }
        if ($desde && $hasta && $hasta < $desde) {
            return ['vigencia_hasta', self::MSG_VIGENCIA];
        }
        $c->setVigenciaDesde($desde)->setVigenciaHasta($hasta);

        return null;
    }

    /**
     * Valores para el template: lo que se acaba de enviar (si se vuelve a
     * mostrar el formulario por un error) o lo guardado en el contenido.
     *
     * @return array<string, mixed>
     */
    public function valores(?Contenido $c, ?Request $request = null): array
    {
        if ($request && $request->isMethod('POST')) {
            $p = $request->request;
            return [
                'plantilla'           => $p->getString('plantilla'),
                'tema'                => $p->getString('tema') ?: TemaPantalla::Automatico->value,
                'marca_agua'          => $p->getBoolean('marca_agua'),
                'cronograma_origen'   => $p->getString('cronograma_origen') ?: OrigenCronograma::Propio->value,
                'cronograma_edificio' => $p->getString('cronograma_edificio'),
                'vigencia_desde'      => $p->getString('vigencia_desde'),
                'vigencia_hasta'      => $p->getString('vigencia_hasta'),
                'segundos_por_pagina' => $p->getString('segundos_por_pagina'),
            ];
        }

        return [
            // Vacío en el alta: content-type-fields.js pone la del tipo elegido.
            'plantilla'           => $c?->getId() ? $c->getPlantilla()->value : '',
            'tema'                => ($c?->getId() ? $c->getTema() : TemaPantalla::Automatico)->value,
            'marca_agua'          => $c?->isMarcaAgua() ?? false,
            'cronograma_origen'   => ($c?->getCronogramaOrigen() ?? OrigenCronograma::Propio)->value,
            'cronograma_edificio' => (string) ($c?->getCronogramaEdificio()?->getId() ?? ''),
            'vigencia_desde'      => $c?->getVigenciaDesde()?->format('Y-m-d') ?? '',
            'vigencia_hasta'      => $c?->getVigenciaHasta()?->format('Y-m-d') ?? '',
            'segundos_por_pagina' => (string) ($c?->getSegundosPorPagina() ?? self::SEG_PAGINA_DEFECTO),
        ];
    }

    /** @return list<Edificio> Para el selector del cronograma, por nombre. */
    public function edificios(): array
    {
        return $this->edificios->findBy([], ['nombre' => 'ASC']);
    }

    /** null si vino vacía, false si no es una fecha Y-m-d válida. */
    private function fecha(string $valor): DateTimeImmutable|false|null
    {
        if (trim($valor) === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        return $d && $d->format('Y-m-d') === $valor ? $d : false;
    }
}
