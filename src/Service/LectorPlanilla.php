<?php

declare(strict_types=1);

namespace SPUI\Service;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lee una planilla (.xlsx o .csv) como grillas de valores, una por hoja
 * (noviembre 2026, para importar cronogramas).
 *
 * Sin librería: un .xlsx es un zip con XML y PHP ya trae lo necesario
 * (zip + SimpleXML), así no se suma ninguna dependencia al monorepo. Alcanza
 * para lo que trae el Excel de horarios: textos, números y celdas
 * combinadas. Los valores llegan crudos (un número es un float, una fecha es
 * su número de serie): interpretarlos es cosa de quien sabe qué columna es.
 *
 * Cada hoja: ['filas' => list<list<string|float|null>>, 'combinadas' =>
 * list<array{0:int,1:int,2:int,3:int}>] (fila y columna desde 0, de arriba a
 * la izquierda hasta abajo a la derecha).
 */
class LectorPlanilla
{
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Más que esto no es una planilla de horarios: no se lee entera. */
    private const MAX_FILAS = 5000;
    private const MAX_COLUMNAS = 40;

    /**
     * @return array<string, array{filas: list<list<string|float|null>>, combinadas: list<array{0:int,1:int,2:int,3:int}>}>
     * @throws RuntimeException con un mensaje para mostrar, si el archivo no se puede leer.
     */
    public function leer(string $ruta, string $nombreOriginal): array
    {
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        return match ($ext) {
            'xlsx'  => $this->leerXlsx($ruta),
            'csv'   => [pathinfo($nombreOriginal, PATHINFO_FILENAME) => $this->leerCsv($ruta)],
            default => throw new RuntimeException('El archivo tiene que ser una planilla de Excel (.xlsx) o un .csv.'),
        };
    }

    private function leerXlsx(string $ruta): array
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo. ¿Es una planilla de Excel (.xlsx)?');
        }

        try {
            $libro = $this->xml($zip, 'xl/workbook.xml');
            $rels  = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            if (!$libro || !$rels) {
                throw new RuntimeException('El archivo no parece una planilla de Excel (.xlsx).');
            }

            $destinos = [];
            foreach ($rels->Relationship as $r) {
                $destinos[(string) $r['Id']] = (string) $r['Target'];
            }
            $textos = $this->textosCompartidos($zip);

            $hojas = [];
            foreach ($libro->sheets->sheet as $hoja) {
                if ((string) $hoja['state'] === 'hidden' || (string) $hoja['state'] === 'veryHidden') {
                    continue;
                }
                $id      = (string) $hoja->attributes(self::NS_REL)['id'];
                $destino = $destinos[$id] ?? null;
                if ($destino === null) {
                    continue;
                }
                $camino = str_starts_with($destino, '/') ? ltrim($destino, '/') : 'xl/' . $destino;
                $xml    = $this->xml($zip, $camino);
                if ($xml) {
                    $hojas[trim((string) $hoja['name'])] = $this->leerHoja($xml, $textos);
                }
            }
        } finally {
            $zip->close();
        }

        if (!$hojas) {
            throw new RuntimeException('La planilla no tiene ninguna hoja con datos.');
        }
        return $hojas;
    }

    /** @return list<string> */
    private function textosCompartidos(ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/sharedStrings.xml');
        $textos = [];
        foreach ($xml?->si ?? [] as $si) {
            // Un texto con formato mezclado viene en partes (<r><t>); la
            // guía fonética (<rPh>) no es parte del texto.
            $t = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r as $r) {
                $t .= (string) $r->t;
            }
            $textos[] = $t;
        }
        return $textos;
    }

    private function leerHoja(SimpleXMLElement $xml, array $textos): array
    {
        $filas = [];
        foreach ($xml->sheetData->row ?? [] as $fila) {
            $f = (int) $fila['r'] - 1;
            if ($f >= self::MAX_FILAS) {
                break;
            }
            foreach ($fila->c as $c) {
                [$cf, $cc] = $this->celda((string) $c['r']);
                if ($cc >= self::MAX_COLUMNAS) {
                    continue;
                }
                $valor = match ((string) $c['t']) {
                    's'         => $textos[(int) $c->v] ?? '',
                    'inlineStr' => (string) ($c->is->t ?? ''),
                    'str', 'e'  => (string) $c->v,
                    'b'         => (string) $c->v === '1' ? 'VERDADERO' : 'FALSO',
                    default     => isset($c->v) && (string) $c->v !== '' ? (float) (string) $c->v : null,
                };
                if (is_string($valor)) {
                    $valor = trim($valor);
                    if ($valor === '') {
                        $valor = null;
                    }
                }
                if ($valor !== null) {
                    $filas[$cf][$cc] = $valor;
                }
            }
        }

        $combinadas = [];
        foreach ($xml->mergeCells->mergeCell ?? [] as $m) {
            $partes = explode(':', (string) $m['ref']);
            if (count($partes) === 2) {
                [$f1, $c1] = $this->celda($partes[0]);
                [$f2, $c2] = $this->celda($partes[1]);
                $combinadas[] = [$f1, $c1, $f2, $c2];
            }
        }

        return ['filas' => $this->compactar($filas), 'combinadas' => $combinadas];
    }

    private function leerCsv(string $ruta): array
    {
        $contenido = file_get_contents($ruta);
        if ($contenido === false || trim($contenido) === '') {
            throw new RuntimeException('El archivo está vacío.');
        }
        // Un CSV guardado desde Excel en Windows suele venir en Windows-1252.
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $lineas = preg_split('/\r\n|\r|\n/', $contenido);
        // Excel en español separa con punto y coma; en inglés, con coma.
        $sep = substr_count($lineas[0], ';') >= substr_count($lineas[0], ',') ? ';' : ',';

        $filas = [];
        foreach (array_slice($lineas, 0, self::MAX_FILAS) as $i => $linea) {
            foreach (str_getcsv($linea, $sep, '"', '') as $c => $v) {
                $v = trim((string) $v);
                if ($v !== '' && $c < self::MAX_COLUMNAS) {
                    $filas[$i][$c] = $v;
                }
            }
        }
        return ['filas' => $this->compactar($filas), 'combinadas' => []];
    }

    /** "B12" → [11, 1]. */
    private function celda(string $ref): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', strtoupper($ref), $m);
        $col = 0;
        foreach (str_split($m[1] ?? 'A') as $letra) {
            $col = $col * 26 + (ord($letra) - 64);
        }
        return [(int) ($m[2] ?? 1) - 1, $col - 1];
    }

    /** @param array<int, array<int, mixed>> $filas → list<list<mixed>> con huecos en null, sin filas vacías al final. */
    private function compactar(array $filas): array
    {
        if (!$filas) {
            return [];
        }
        $ancho = 0;
        foreach ($filas as $f) {
            $ancho = max($ancho, max(array_keys($f)) + 1);
        }
        $salida = [];
        for ($i = 0, $n = max(array_keys($filas)); $i <= $n; $i++) {
            $fila = array_fill(0, $ancho, null);
            foreach ($filas[$i] ?? [] as $c => $v) {
                $fila[$c] = $v;
            }
            $salida[] = $fila;
        }
        return $salida;
    }

    private function xml(ZipArchive $zip, string $camino): ?SimpleXMLElement
    {
        $texto = $zip->getFromName($camino);
        if ($texto === false) {
            return null;
        }
        $previo = libxml_use_internal_errors(true);
        // Sin entidades externas ni red: es un archivo que subió alguien.
        $xml = simplexml_load_string($texto, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previo);
        return $xml ?: null;
    }
}
