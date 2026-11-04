<?php

declare(strict_types=1);

namespace SPUI\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use ZipArchive;

/**
 * Un .xlsx de una sola hoja: encabezado en negrita, fila fija, filtro y
 * columnas tipadas (fecha, decimal, entero, texto).
 *
 * Hecho a mano y no con PhpSpreadsheet porque el monorepo no la tiene, y
 * sumarla sería una dependencia nueva para todas las apps de la intranet por
 * una tabla. Un .xlsx es un zip con unos pocos XML (Office Open XML): esto
 * escribe sólo lo que Excel y LibreOffice necesitan para abrirlo sin quejarse.
 *
 * Las fechas van como número de serie de Excel con formato de fecha, no como
 * texto: así se pueden ordenar, filtrar y graficar en la planilla.
 */
final class XlsxSimpleService
{
    // Índices de cellXfs en styles.xml.
    private const ESTILO_ENCABEZADO = 1;
    private const ESTILO_FECHA      = 2;
    private const ESTILO_DECIMAL    = 3;

    /**
     * @param list<array{titulo:string, tipo:string, ancho?:int}> $columnas tipo: fecha|decimal|entero|texto
     * @param list<list<mixed>>                                  $filas    valores en el orden de $columnas
     * @return string el contenido binario del .xlsx
     */
    public function generar(string $hoja, array $columnas, array $filas): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'spui-xlsx-');
        $zip = new ZipArchive();
        if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo temporal del Excel.');
        }

        $zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES);
        $zip->addFromString('_rels/.rels', self::RELS);
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS);
        $zip->addFromString('xl/styles.xml', self::STYLES);
        $zip->addFromString('xl/workbook.xml', sprintf(self::WORKBOOK, $this->esc(mb_substr($hoja, 0, 31))));
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->hoja($columnas, $filas));
        $zip->close();

        $binario = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $binario;
    }

    private function hoja(array $columnas, array $filas): string
    {
        $ultimaCol = $this->letra(count($columnas) - 1);
        $ultimaFila = count($filas) + 1;

        $cols = '';
        foreach ($columnas as $i => $c) {
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $i + 1, $c['ancho'] ?? 14);
        }

        $xml = '<row r="1">';
        foreach ($columnas as $i => $c) {
            $xml .= sprintf('<c r="%s1" t="inlineStr" s="%d"><is><t>%s</t></is></c>', $this->letra($i), self::ESTILO_ENCABEZADO, $this->esc($c['titulo']));
        }
        $xml .= '</row>';

        foreach ($filas as $n => $fila) {
            $r = $n + 2;
            $xml .= '<row r="' . $r . '">';
            foreach ($columnas as $i => $c) {
                $xml .= $this->celda($this->letra($i) . $r, $c['tipo'], $fila[$i] ?? null);
            }
            $xml .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . $xml . '</sheetData>'
            . sprintf('<autoFilter ref="A1:%s%d"/>', $ultimaCol, $ultimaFila)
            . '</worksheet>';
    }

    private function celda(string $ref, string $tipo, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        return match ($tipo) {
            'fecha'   => sprintf('<c r="%s" s="%d"><v>%s</v></c>', $ref, self::ESTILO_FECHA, $this->serialExcel($valor)),
            'decimal' => sprintf('<c r="%s" s="%d"><v>%s</v></c>', $ref, self::ESTILO_DECIMAL, (float) $valor),
            'entero'  => sprintf('<c r="%s"><v>%d</v></c>', $ref, (int) $valor),
            default   => sprintf('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>', $ref, $this->esc((string) $valor)),
        };
    }

    /** Excel cuenta días desde el 30/12/1899 y no sabe de zonas horarias: va la hora local tal cual. */
    private function serialExcel(DateTimeInterface $fecha): string
    {
        $comoUtc = new DateTimeImmutable($fecha->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
        // 10 decimales: con 6, las 08:00 quedaban en 07:59:59,97 y la planilla
        // mostraba 07:59 (el formato hh:mm trunca, no redondea).
        return sprintf('%.10F', $comoUtc->getTimestamp() / 86400 + 25569);
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private function letra(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private const CONTENT_TYPES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    private const RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    private const WORKBOOK_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    private const WORKBOOK = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="%s" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1"/></definedNames>'
        . '</workbook>';

    // 0: normal · 1: encabezado (negrita, fondo azul claro) · 2: fecha y hora · 3: un decimal.
    private const STYLES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy hh:mm"/><numFmt numFmtId="165" formatCode="0.0"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}
