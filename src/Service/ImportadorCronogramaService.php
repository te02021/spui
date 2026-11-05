<?php

declare(strict_types=1);

namespace SPUI\Service;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;
use SPUI\Entity\Contenido;
use SPUI\Entity\CronogramaItem;
use SPUI\Enum\EstadoContenido;
use SPUI\Enum\PlantillaPantalla;
use SPUI\Enum\TemaPantalla;
use SPUI\Enum\TipoContenido;
use SPUI\Enum\TipoItemCronograma;
use SPUI\Repository\ContenidoRepository;
use SPUI\Repository\EdificioRepository;

/**
 * Importa el Excel de horarios a cronogramas (noviembre 2026).
 *
 * El Excel de la UNRaf tiene una hoja por edificio (Día, Aula, Horario,
 * Carrera, Año, Materia, Docente; Día y Aula combinados hacia abajo) y una de
 * "Eventos especiales" (Edificio, Fecha, Hora inicio, Hora final, Aula,
 * Evento, Responsable). Las columnas se reconocen por su título, no por su
 * posición, y lo que viene escrito de más de una forma se lee con una regla
 * fija, anotando cada corrección para mostrarla antes de guardar:
 *
 *   - Carrera y Año invertidos ("3 ERO" en Carrera): se dan vuelta.
 *   - Años "1 ERO", "1ero", "2DO/4TO" → "1.º", "2.º y 4.º".
 *   - Horarios "16 a 18", "13:30 a 15:30" y también "18:00 20:00" o "18 a 20: 00".
 *   - Aulas: 1.0 → "1", "6A" → "6 A", sin la capacidad entre paréntesis.
 *   - Una actividad que ocupa de Carrera a Docente (Arena de Esports) o una
 *     diplomatura que ocupa de Año a Docente: tipo actividad.
 *   - La misma clase en varios días se guarda una vez, con todos sus días.
 *   - Eventos: horas 9.3 = 9:30, sin hora de fin ("-") = "Desde las…"; los
 *     que ya pasaron no se importan.
 *
 * Nada se descarta en silencio: lo que no se puede leer va a la lista de
 * errores con su número de fila.
 */
class ImportadorCronogramaService
{
    private const DIAS = ['lunes' => 0, 'martes' => 1, 'miercoles' => 2, 'jueves' => 3, 'viernes' => 4, 'sabado' => 5, 'domingo' => 6];
    private const NOMBRES_DIA = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    private const RE_ANIO = '/^\s*\d\s*(?:ero|ro|do|to|er|°|º)?(?:\s*(?:\/|y|-|,)\s*\d\s*(?:ero|ro|do|to|er|°|º)?)*\s*$/iu';
    private const RE_HORA = '(\d{1,2})(?:\s*[:.,]\s*(\d{2}))?\s*(?:hs?\.?)?';
    private const MAX = ['nombre' => 200, 'aula' => 100, 'carrera' => 60, 'anio' => 40, 'docente' => 255];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ContenidoRepository $contenidos,
        private readonly EdificioRepository $edificios,
    ) {}

    // ── Lectura ─────────────────────────────────────────────────────────────

    /**
     * Lo que se va a importar, sin guardar nada. Es un array simple (va a la
     * sesión entre la vista previa y Guardar).
     *
     * @param array<string, array{filas: list<list<mixed>>, combinadas: list<array>}> $hojas De LectorPlanilla.
     */
    public function interpretar(array $hojas, ?DateTimeImmutable $hoy = null): array
    {
        $hoy  = ($hoy ?? new DateTimeImmutable())->setTime(0, 0);
        $plan = ['hojas' => [], 'eventos' => null, 'ignoradas' => []];

        foreach ($hojas as $nombre => $hoja) {
            $enc = $this->encabezado($hoja['filas']);
            if ($enc === null) {
                $plan['ignoradas'][] = $nombre;
            } elseif ($enc['tipo'] === 'horarios') {
                $plan['hojas'][] = ['nombre' => $nombre] + $this->leerHorarios($hoja, $enc);
            } elseif ($plan['eventos'] === null) {
                $plan['eventos'] = ['nombre' => $nombre] + $this->leerEventos($hoja, $enc, $hoy);
            } else {
                $plan['ignoradas'][] = $nombre;
            }
        }

        if (!$plan['hojas']) {
            throw new RuntimeException(
                'No se encontró ninguna hoja de horarios: hace falta una fila de títulos con al menos "Día", "Horario" y "Materia".'
            );
        }
        return $plan;
    }

    /** La fila de títulos (en las primeras 5) y qué columna es cada dato. */
    private function encabezado(array $filas): ?array
    {
        $buscar = [
            'horarios' => ['dia' => ['dia'], 'aula' => ['aula'], 'horario' => ['horario', 'horarios'], 'carrera' => ['carrera'],
                'anio' => ['ano', 'anio'], 'materia' => ['materia', 'asignatura', 'actividad'], 'docente' => ['docente', 'docentes']],
            'eventos'  => ['edificio' => ['edificio'], 'fecha' => ['fecha'], 'inicio' => ['hora inicio', 'hora de inicio', 'inicio'],
                'fin' => ['hora final', 'hora fin', 'hora de fin', 'fin'], 'aula' => ['aula', 'lugar'],
                'evento' => ['evento a realizar', 'evento', 'actividad'], 'responsable' => ['responsable de actividad', 'responsable']],
        ];
        $obligatorias = ['horarios' => ['dia', 'horario', 'materia'], 'eventos' => ['fecha', 'inicio', 'evento']];

        foreach (array_slice($filas, 0, 5, true) as $i => $fila) {
            $titulos = array_map(fn($v) => is_string($v) ? $this->normalizar($v) : '', $fila);
            foreach ($buscar as $tipo => $campos) {
                $cols = [];
                foreach ($campos as $campo => $nombres) {
                    foreach ($titulos as $c => $t) {
                        if ($t !== '' && in_array($t, $nombres, true) && !in_array($c, $cols, true)) {
                            $cols[$campo] = $c;
                            break;
                        }
                    }
                }
                if (!array_diff($obligatorias[$tipo], array_keys($cols))) {
                    return ['tipo' => $tipo, 'fila' => $i, 'cols' => $cols];
                }
            }
        }
        return null;
    }

    private function leerHorarios(array $hoja, array $enc): array
    {
        $filas = $hoja['filas'];
        $comb  = $this->mapaCombinadas($hoja['combinadas'], $filas);
        $col   = $enc['cols'];
        $valor = fn(int $f, ?int $c) => $c === null ? null : ($comb["$f:$c"][0] ?? null) ?? ($filas[$f][$c] ?? null);
        $rango = fn(int $f, ?int $c) => $c === null ? null : ($comb["$f:$c"][1] ?? null);

        $correcciones = $errores = [];
        $unicas = [];
        $leidas = 0;
        $dia = $aula = null;

        for ($f = $enc['fila'] + 1, $n = count($filas); $f < $n; $f++) {
            $fila = $f + 1;   // como la ve quien abre el Excel
            $vDia = $this->texto($valor($f, $col['dia']));
            if ($vDia !== null) {
                $dia = self::DIAS[$this->normalizar($vDia)] ?? null;
                if ($dia === null) {
                    $errores[] = ['fila' => $fila, 'texto' => "No se reconoce el día «{$vDia}»: se saltean sus clases."];
                }
            }
            $vAula = $valor($f, $col['aula'] ?? null);
            if ($vAula !== null) {
                $aula = $this->aula($vAula);
            }

            $horario = $this->texto($valor($f, $col['horario']));
            $carrera = $this->texto($valor($f, $col['carrera'] ?? null));
            $anio    = $this->texto($valor($f, $col['anio'] ?? null));
            $materia = $this->texto($valor($f, $col['materia']));
            $docente = $this->texto($valor($f, $col['docente'] ?? null));
            $tipo    = TipoItemCronograma::Clase;

            if ($horario === null && $carrera === null && $anio === null && $materia === null) {
                continue;   // fila vacía, o un aula sin clases ese día
            }
            if ($dia === null) {
                if ($vDia === null) {
                    $errores[] = ['fila' => $fila, 'texto' => 'No tiene día.'];
                }
                continue;
            }

            // Una actividad que no es una clase ocupa de Carrera a Docente
            // ("Arena de Esports"); una diplomatura, de Año a Docente.
            $rc = $rango($f, $col['carrera'] ?? null);
            $ra = $rango($f, $col['anio'] ?? null);
            if ($rc && $rc[3] >= $col['materia']) {
                [$materia, $carrera, $anio, $docente, $tipo] = [$carrera, null, null, null, TipoItemCronograma::Actividad];
            } elseif ($ra && isset($col['docente']) && $ra[3] >= $col['docente'] && $ra[1] === ($col['anio'] ?? -1)) {
                [$materia, $anio, $docente, $tipo] = [$anio, null, null, TipoItemCronograma::Actividad];
            } elseif ($carrera && $anio && preg_match(self::RE_ANIO, $carrera) && !preg_match(self::RE_ANIO, $anio)) {
                [$carrera, $anio] = [$anio, $carrera];
                $correcciones[] = ['fila' => $fila, 'texto' => "Carrera y año estaban invertidos: carrera {$carrera}, año {$this->anio($anio)}."];
            }

            // Lo mismo sin celdas combinadas (un CSV, que no las tiene): sin
            // materia, el texto que no es un año en Año o en Carrera es el
            // nombre de la actividad.
            if ($materia === null && $docente === null) {
                if ($anio !== null && !preg_match(self::RE_ANIO, $anio)) {
                    [$materia, $anio, $tipo] = [$anio, null, TipoItemCronograma::Actividad];
                } elseif ($carrera !== null && $anio === null && !preg_match(self::RE_ANIO, $carrera)) {
                    [$materia, $carrera, $tipo] = [$carrera, null, TipoItemCronograma::Actividad];
                }
            }
            if ($materia === null) {
                $errores[] = ['fila' => $fila, 'texto' => 'No tiene materia ni actividad.'];
                continue;
            }
            $horas = $this->rango((string) $horario);
            if ($horas === null) {
                $errores[] = ['fila' => $fila, 'texto' => $horario === null ? "«{$materia}» no tiene horario." : "No se entiende el horario «{$horario}» de «{$materia}»."];
                continue;
            }
            [$inicio, $fin, $canonico] = $horas;
            if (!$canonico) {
                $correcciones[] = ['fila' => $fila, 'texto' => "«{$horario}» se leyó como {$inicio} a {$fin}."];
            }
            if ($fin <= $inicio) {
                $errores[] = ['fila' => $fila, 'texto' => "El horario «{$horario}» de «{$materia}» termina antes de empezar."];
                continue;
            }

            $item = [
                'tipo'    => $tipo->value,
                'nombre'  => $this->materia($materia),
                'aula'    => $aula,
                'carrera' => $carrera !== null && $carrera !== '-' ? preg_replace('/\s*\/\s*/', '/', $carrera) : null,
                'anio'    => $anio !== null ? $this->anio($anio) : null,
                'docente' => $this->docente($docente),
                'inicio'  => $inicio,
                'fin'     => $fin,
            ];
            if ($e = $this->excedido($item)) {
                $errores[] = ['fila' => $fila, 'texto' => $e];
                continue;
            }
            $leidas++;

            // La misma clase en otro día: un solo ítem con los dos días.
            $clave = implode("\0", $item);
            $unicas[$clave] ??= $item + ['dias' => 0];
            $unicas[$clave]['dias'] |= 1 << $dia;
        }

        $porDia = array_fill(0, 7, 0);
        foreach ($unicas as $it) {
            for ($b = 0; $b < 7; $b++) {
                $porDia[$b] += ($it['dias'] >> $b) & 1;
            }
        }

        return [
            'items'        => array_values($unicas),
            'leidas'       => $leidas,
            'por_dia'      => array_filter(array_combine(self::NOMBRES_DIA, $porDia)),
            'correcciones' => $correcciones,
            'errores'      => $errores,
        ];
    }

    private function leerEventos(array $hoja, array $enc, DateTimeImmutable $hoy): array
    {
        $col = $enc['cols'];
        $grupos = $correcciones = $errores = [];
        $valor = fn(array $fila, string $campo) => isset($col[$campo]) ? ($fila[$col[$campo]] ?? null) : null;

        foreach ($hoja['filas'] as $f => $filaDatos) {
            if ($f <= $enc['fila'] || !array_filter($filaDatos, fn($v) => $v !== null)) {
                continue;
            }
            $fila   = $f + 1;
            $nombre = $this->texto($valor($filaDatos, 'evento'));
            if ($nombre === null) {
                $errores[] = ['fila' => $fila, 'texto' => 'No dice qué evento es.'];
                continue;
            }
            $fecha = $this->fecha($valor($filaDatos, 'fecha'));
            if ($fecha === null) {
                $errores[] = ['fila' => $fila, 'texto' => "«{$nombre}» no tiene una fecha válida."];
                continue;
            }
            [$inicio, $notaI] = $this->hora($valor($filaDatos, 'inicio'));
            [$fin, $notaF]    = $this->hora($valor($filaDatos, 'fin'));
            if ($inicio === null) {
                $errores[] = ['fila' => $fila, 'texto' => "«{$nombre}» no tiene una hora de inicio válida."];
                continue;
            }
            if ($fin !== null && $fin <= $inicio) {
                $errores[] = ['fila' => $fila, 'texto' => "«{$nombre}» termina antes de empezar."];
                continue;
            }
            foreach ([$notaI, $notaF] as $nota) {
                if ($nota) {
                    $correcciones[] = ['fila' => $fila, 'texto' => $nota];
                }
            }
            if ($fin === null) {
                $correcciones[] = ['fila' => $fila, 'texto' => "«{$nombre}» no tiene hora de fin: se mostrará «Desde las {$inicio}»."];
            }

            $edificio = $this->texto($valor($filaDatos, 'edificio')) ?? 'Sin edificio';
            $grupos[$edificio] ??= ['edificio' => $edificio, 'items' => [], 'pasados' => 0];
            if ($fecha < $hoy) {
                $grupos[$edificio]['pasados']++;
                continue;
            }
            $item = [
                'tipo'    => TipoItemCronograma::Evento->value,
                'nombre'  => $this->materia($nombre),
                'aula'    => ($a = $valor($filaDatos, 'aula')) !== null ? $this->aula($a) : null,
                'carrera' => null,
                'anio'    => null,
                'docente' => $this->docente($this->texto($valor($filaDatos, 'responsable'))),
                'inicio'  => $inicio,
                'fin'     => $fin,
                'fecha'   => $fecha->format('Y-m-d'),
            ];
            if ($e = $this->excedido($item)) {
                $errores[] = ['fila' => $fila, 'texto' => $e];
                continue;
            }
            $grupos[$edificio]['items'][] = $item;
        }

        return ['grupos' => array_values($grupos), 'correcciones' => $correcciones, 'errores' => $errores];
    }

    // ── Sugerencias para la vista previa ────────────────────────────────────

    /**
     * Qué proponer de entrada: para cada hoja, el edificio cuyo nombre
     * coincide y, si ese edificio ya tiene un cronograma, reemplazarlo;
     * para cada grupo de eventos, la hoja del mismo nombre.
     */
    public function sugerencias(array $plan): array
    {
        $edificios = $this->edificios->findBy([], ['nombre' => 'ASC']);
        $hojas = [];
        foreach ($plan['hojas'] as $h) {
            $nombre   = $this->normalizar($h['nombre']);
            $edificio = null;
            foreach ($edificios as $e) {
                $en = $this->normalizar($e->getNombre());
                if ($en === $nombre || str_contains($en, $nombre) || str_contains($nombre, $en)) {
                    $edificio = $e;
                    break;
                }
            }
            $existente = $edificio ? $this->contenidos->findOneBy(
                ['tipo' => TipoContenido::Cronograma, 'cronogramaEdificio' => $edificio],
                ['actualizadoEn' => 'DESC'],
            ) : null;
            $hojas[$h['nombre']] = [
                'edificio' => $edificio?->getId(),
                'destino'  => $existente ? (string) $existente->getId() : 'nuevo',
            ];
        }

        $eventos = [];
        foreach ($plan['eventos']['grupos'] ?? [] as $g) {
            $eventos[$g['edificio']] = '';
            foreach ($plan['hojas'] as $h) {
                if ($this->normalizar($h['nombre']) === $this->normalizar($g['edificio'])) {
                    $eventos[$g['edificio']] = $h['nombre'];
                }
            }
        }

        return ['hojas' => $hojas, 'eventos' => $eventos];
    }

    // ── Guardar ─────────────────────────────────────────────────────────────

    /**
     * Crea o reemplaza los cronogramas, todo o nada.
     *
     * @param array{hojas: array<string, array{destino: string, titulo: string, edificio: string}>,
     *              eventos: array<string, string>, desde: ?DateTimeImmutable, hasta: ?DateTimeImmutable} $opciones
     * @return list<array{contenido: Contenido, cantidad: int, nuevo: bool}>
     */
    public function aplicar(array $plan, array $opciones, int $usuarioId): array
    {
        $em = $this->doctrine->getManager('SPUI');

        // Los eventos van a la hoja que se eligió para su edificio.
        $eventosDe = [];
        foreach ($plan['eventos']['grupos'] ?? [] as $g) {
            $hoja = $opciones['eventos'][$g['edificio']] ?? '';
            if ($hoja !== '') {
                $eventosDe[$hoja] = array_merge($eventosDe[$hoja] ?? [], $g['items']);
            }
        }

        return $em->wrapInTransaction(function () use ($em, $plan, $opciones, $usuarioId, $eventosDe): array {
            $hechos = [];
            foreach ($plan['hojas'] as $h) {
                $op = $opciones['hojas'][$h['nombre']] ?? ['destino' => 'omitir'];
                if ($op['destino'] === 'omitir') {
                    continue;
                }

                if ($op['destino'] === 'nuevo') {
                    $c = (new Contenido())
                        ->setTipo(TipoContenido::Cronograma)
                        ->setTitulo(mb_substr(trim($op['titulo']) ?: 'Cronograma ' . $h['nombre'], 0, 200))
                        ->setEstado(EstadoContenido::Disponible)
                        ->setCreadoPorId($usuarioId)
                        ->setPlantilla(PlantillaPantalla::Institucional)
                        ->setTema(TemaPantalla::Automatico)
                        ->setSegundosPorPagina(PresentacionContenidoService::SEG_PAGINA_DEFECTO);
                    $em->persist($c);
                } else {
                    $c = $this->contenidos->find((int) $op['destino']);
                    if (!$c || !$c->esCronograma()) {
                        throw new RuntimeException("El cronograma elegido para «{$h['nombre']}» ya no existe. Volvé a elegir el destino.");
                    }
                    $em->createQuery('DELETE FROM SPUI\Entity\CronogramaItem i WHERE i.contenido = :c')
                        ->setParameter('c', $c)->execute();
                }

                $c->setCronogramaEdificio($op['edificio'] !== '' ? $this->edificios->find((int) $op['edificio']) : null)
                    ->setVigenciaDesde($opciones['desde'])
                    ->setVigenciaHasta($opciones['hasta']);

                $orden = 0;
                foreach (array_merge($h['items'], $eventosDe[$h['nombre']] ?? []) as $it) {
                    $em->persist($this->item($c, $it, ++$orden));
                }
                $hechos[] = ['contenido' => $c, 'cantidad' => $orden, 'nuevo' => $op['destino'] === 'nuevo'];
            }
            $em->flush();
            return $hechos;
        });
    }

    private function item(Contenido $c, array $it, int $orden): CronogramaItem
    {
        $tipo = TipoItemCronograma::from($it['tipo']);
        $item = (new CronogramaItem())
            ->setContenido($c)
            ->setTipo($tipo)
            ->setNombre($it['nombre'])
            ->setAula($it['aula'])
            ->setCarrera($it['carrera'])
            ->setAnio($it['anio'])
            ->setDocente($it['docente'])
            ->setHoraInicio(new DateTimeImmutable($it['inicio']))
            ->setHoraFin($it['fin'] !== null ? new DateTimeImmutable($it['fin']) : null)
            ->setFecha(isset($it['fecha']) ? new DateTimeImmutable($it['fecha']) : null)
            ->setActivo(true)
            ->setOrden($orden);
        if (!$tipo->tieneFecha()) {
            $item->setDiasSemana($it['dias']);
        }
        return $item;
    }

    // ── Reglas de lectura ───────────────────────────────────────────────────

    /** "fila:columna" → [valor de arriba a la izquierda, rango] para cada celda combinada. */
    private function mapaCombinadas(array $combinadas, array $filas): array
    {
        $mapa = [];
        foreach ($combinadas as [$f1, $c1, $f2, $c2]) {
            $valor = $filas[$f1][$c1] ?? null;
            for ($f = $f1; $f <= $f2; $f++) {
                for ($c = $c1; $c <= $c2; $c++) {
                    $mapa["$f:$c"] = [$valor, [$f1, $c1, $f2, $c2]];
                }
            }
        }
        return $mapa;
    }

    /** Minúsculas, sin acentos ni puntos ni espacios de más: para comparar títulos y nombres. */
    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '.' => '']);
        return preg_replace('/\s+/', ' ', $s);
    }

    /** Un valor de celda como texto: 1.0 → "1"; vacío → null. */
    private function texto(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_float($v)) {
            return floor($v) === $v ? (string) (int) $v : (string) $v;
        }
        $v = preg_replace('/\s+/u', ' ', trim((string) $v));
        return $v === '' ? null : $v;
    }

    /** [inicio, fin, ¿estaba escrito como "H a H"?] o null si no se entiende. */
    private function rango(string $txt): ?array
    {
        $txt = trim($txt);
        if (!preg_match('/^' . self::RE_HORA . '\s*(?:a|-|–|al|hasta)?\s*' . self::RE_HORA . '$/iu', $txt, $m)) {
            return null;
        }
        $inicio = $this->hhmm((int) $m[1], (int) ($m[2] ?? 0));
        $fin    = $this->hhmm((int) $m[3], (int) ($m[4] ?? 0));
        if ($inicio === null || $fin === null) {
            return null;
        }
        $canonico = (bool) preg_match('/^\d{1,2}(:\d{2})? a \d{1,2}(:\d{2})?$/', $txt);
        return [$inicio, $fin, $canonico];
    }

    private function hhmm(int $h, int $m): ?string
    {
        return $h <= 23 && $m <= 59 ? sprintf('%02d:%02d', $h, $m) : null;
    }

    /**
     * Una hora de la hoja de eventos: 8 → 08:00, 9.3 → 09:30 (escrita con
     * punto), 0.354 → 08:30 (formato hora de Excel), "18:00", "-" → sin hora.
     *
     * @return array{0: ?string, 1: ?string} [hora, nota de corrección]
     */
    private function hora(mixed $v): array
    {
        if ($v === null || (is_string($v) && trim($v, " -–") === '')) {
            return [null, null];
        }
        if (is_float($v)) {
            if ($v < 1) {
                $min = (int) round($v * 1440);
                return [$this->hhmm(intdiv($min, 60) % 24, $min % 60), null];
            }
            $h  = (int) floor($v);
            $mm = (int) round(($v - $h) * 100);
            $hora = $this->hhmm($h, $mm);
            return [$hora, $mm > 0 && $hora ? "«{$this->texto($v)}» se leyó como {$hora}." : null];
        }
        if (preg_match('/^' . self::RE_HORA . '$/iu', trim((string) $v), $m)) {
            return [$this->hhmm((int) $m[1], (int) ($m[2] ?? 0)), null];
        }
        return [null, null];
    }

    /** Fecha de la hoja de eventos: número de serie de Excel, "dd/mm/aaaa" o "aaaa-mm-dd". */
    private function fecha(mixed $v): ?DateTimeImmutable
    {
        if (is_float($v) && $v > 1) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) floor($v) . ' days');
        }
        if (is_string($v)) {
            foreach (['!d/m/Y', '!d/m/y', '!Y-m-d'] as $formato) {
                $d = DateTimeImmutable::createFromFormat($formato, trim($v));
                if ($d) {
                    return $d;
                }
            }
        }
        return null;
    }

    /** 1.0 → "1", "6A" → "6 A", "Auditorio (80)" → "Auditorio". */
    private function aula(mixed $v): ?string
    {
        $s = $this->texto($v);
        if ($s === null) {
            return null;
        }
        $s = trim(preg_replace('/\s*\(\d+\)\s*/', ' ', $s));
        // "101,102,103" con espacio después de cada coma: si no, en el TV es
        // una sola palabra larguísima que no puede bajar de renglón.
        $s = preg_replace('/\s*,\s*/', ', ', $s);
        return preg_replace('/(\d)\s*([A-Za-z])$/', '$1 $2', $s);
    }

    /** "1 ERO" → "1.º"; "2DO/4TO" → "2.º y 4.º". Si no tiene números, queda como está. */
    private function anio(string $v): string
    {
        preg_match_all('/\d/', $v, $m);
        $nums = array_values(array_unique($m[0]));
        if (!$nums) {
            return $v;
        }
        $nums = array_map(fn($n) => $n . '.º', $nums);
        $ult  = array_pop($nums);
        return $nums ? implode(', ', $nums) . ' y ' . $ult : $ult;
    }

    /** "Corach Paulina// Forni Soledad" → "Corach Paulina / Forni Soledad". */
    private function docente(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $partes = array_filter(array_map('trim', preg_split('#//|/#', $v)), fn($p) => $p !== '');
        return $partes ? implode(' / ', $partes) : null;
    }

    /** Primera letra en mayúscula ("gestion de proyectos" → "Gestion de proyectos"). */
    private function materia(string $v): string
    {
        return mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1);
    }

    private function excedido(array $item): ?string
    {
        foreach (self::MAX as $campo => $max) {
            if ($item[$campo] !== null && mb_strlen($item[$campo]) > $max) {
                return sprintf('«%s» es demasiado largo (hasta %d caracteres).', mb_substr($item[$campo], 0, 40) . '…', $max);
            }
        }
        return null;
    }
}
