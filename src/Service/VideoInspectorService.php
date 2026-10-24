<?php

declare(strict_types=1);

namespace SPUI\Service;

/**
 * Lee de un video lo que hace falta para saber si el reproductor lo puede
 * mostrar fluido, y cuánto dura: resolución, códec, cuadros por segundo y
 * duración.
 *
 * Por qué existe: la Raspberry Pi 4 decodifica por hardware H.264 hasta
 * 1920×1080. Un video 4K (3840×2160) se veía trabado y llegaba a congelar la
 * pantalla, y el CMS lo aceptaba igual. Ahora se rechaza al subirlo, con el
 * motivo debajo del campo, en vez de descubrirlo frente a la pantalla.
 *
 * Por qué así: lee la estructura del archivo (cajas ISO BMFF, el formato de
 * MP4 y de MOV) en vez de usar ffprobe o una librería. ffprobe no está en el
 * servidor, y el composer.json es del monorepo entero: sumar una dependencia
 * para leer cuatro números afecta a todas las apps. Se leen sólo las cajas
 * necesarias:
 *
 *   moov/mvhd                        duración total
 *   moov/trak/mdia/hdlr              cuál pista es la de video ('vide')
 *   moov/trak/mdia/mdhd              escala de tiempo y duración de esa pista
 *   moov/trak/mdia/minf/stbl/stsd    códec (avc1, hvc1…) y ancho × alto
 *   moov/trak/mdia/minf/stbl/stts    cantidad de cuadros, para los fps
 *
 * WebM y OGG no tienen esta estructura: se rechazan antes, por formato.
 */
final class VideoInspectorService
{
    /** Lo que la Pi 4 reproduce fluido, comprobado en el equipo (1080p a 24 fps, 24/10/2026). */
    public const LADO_MAYOR_MAXIMO = 1920;
    public const LADO_MENOR_MAXIMO = 1080;
    public const FPS_MAXIMO        = 30;

    /** H.264: el único códec que la Pi 4 decodifica por hardware con VLC sobre X11. */
    private const CODECS_H264 = ['avc1', 'avc3'];

    private const NOMBRES_CODEC = [
        'hvc1' => 'H.265 (HEVC)', 'hev1' => 'H.265 (HEVC)',
        'vp09' => 'VP9', 'vp08' => 'VP8', 'av01' => 'AV1',
        'mp4v' => 'MPEG-4 Parte 2', 'apcn' => 'ProRes', 'apch' => 'ProRes', 'apcs' => 'ProRes',
        'jpeg' => 'Motion JPEG', 'mjpa' => 'Motion JPEG',
    ];

    /** La caja moov de un video de minutos ocupa KB; esto sólo frena un archivo armado para agotar memoria. */
    private const MOOV_MAXIMO = 64 * 1024 * 1024;

    /**
     * @return array{duracion: float, ancho: int, alto: int, codec: string, fps: ?float}|null
     *         null si el archivo no es un MP4/MOV legible o no tiene pista de video.
     */
    public function inspeccionar(string $ruta): ?array
    {
        $fh = @fopen($ruta, 'rb');
        if ($fh === false) {
            return null;
        }

        try {
            $moov = $this->leerMoov($fh, (int) @filesize($ruta));
        } finally {
            fclose($fh);
        }

        return $moov === null ? null : $this->analizarMoov($moov);
    }

    /**
     * Motivo en español por el que la Pi no lo va a mostrar bien, o null si sirve.
     *
     * @param array{duracion: float, ancho: int, alto: int, codec: string, fps: ?float}|null $info
     */
    public function motivoRechazo(?array $info): ?string
    {
        if ($info === null) {
            return 'No se pudo leer el video. Tiene que ser un MP4 o MOV en H.264; si lo es, '
                 . 'probá exportarlo de nuevo desde el editor de video.';
        }

        if (!in_array($info['codec'], self::CODECS_H264, true)) {
            $nombre = self::NOMBRES_CODEC[$info['codec']] ?? 'un códec que las pantallas no pueden reproducir';
            return sprintf(
                'El video está en %s. Las pantallas reproducen fluido sólo H.264: exportalo en H.264 (MP4) y volvé a subirlo.',
                $nombre,
            );
        }

        $mayor = max($info['ancho'], $info['alto']);
        $menor = min($info['ancho'], $info['alto']);
        if ($mayor > self::LADO_MAYOR_MAXIMO || $menor > self::LADO_MENOR_MAXIMO) {
            return sprintf(
                'El video es de %d×%d y las pantallas reproducen fluido hasta 1920×1080 (Full HD). '
                . 'Subí una versión en 1080p o menos: en una más grande la imagen se traba.',
                $info['ancho'],
                $info['alto'],
            );
        }

        if ($info['fps'] !== null && $info['fps'] > self::FPS_MAXIMO + 0.5) {
            return sprintf(
                'El video tiene %s cuadros por segundo y las pantallas reproducen fluido hasta %d. Exportalo a %d cuadros por segundo.',
                rtrim(rtrim(number_format($info['fps'], 2, ',', ''), '0'), ','),
                self::FPS_MAXIMO,
                self::FPS_MAXIMO,
            );
        }

        if ($info['duracion'] < 1) {
            return 'No se pudo determinar cuánto dura el video. Probá exportarlo de nuevo como MP4.';
        }

        return null;
    }

    /** Duración en segundos enteros, redondeada para arriba: nunca se corta el final. */
    public static function segundos(array $info): int
    {
        return max(1, (int) ceil($info['duracion'] - 0.001));
    }

    // ── Lectura de cajas ────────────────────────────────────────────────────

    /** Recorre las cajas del nivel superior hasta encontrar moov, esté al principio o al final. */
    private function leerMoov($fh, int $tamanio): ?string
    {
        $pos = 0;
        while ($tamanio === 0 || $pos + 8 <= $tamanio) {
            if (fseek($fh, $pos) !== 0) {
                return null;
            }
            $cab = fread($fh, 8);
            if ($cab === false || strlen($cab) < 8) {
                return null;
            }
            $largo = unpack('N', substr($cab, 0, 4))[1];
            $tipo  = substr($cab, 4, 4);
            $cabecera = 8;

            if ($largo === 1) {
                $ext = fread($fh, 8);
                if ($ext === false || strlen($ext) < 8) {
                    return null;
                }
                $largo = $this->uint64($ext);
                $cabecera = 16;
            } elseif ($largo === 0) {
                $largo = $tamanio - $pos;   // hasta el final del archivo
            }

            if ($largo < $cabecera) {
                return null;                 // archivo dañado: no seguir a ciegas
            }

            if ($tipo === 'moov') {
                $cuerpo = $largo - $cabecera;
                if ($cuerpo > self::MOOV_MAXIMO) {
                    return null;
                }
                $datos = $cuerpo > 0 ? fread($fh, $cuerpo) : '';
                return ($datos !== false && strlen($datos) === $cuerpo) ? $datos : null;
            }

            $pos += $largo;
        }

        return null;
    }

    /** @return list<array{0:string,1:string}> [tipo, cuerpo] de las cajas hijas. */
    private function cajas(string $datos): array
    {
        $out = [];
        $pos = 0;
        $n = strlen($datos);
        while ($pos + 8 <= $n) {
            $largo = unpack('N', substr($datos, $pos, 4))[1];
            $tipo  = substr($datos, $pos + 4, 4);
            $cabecera = 8;
            if ($largo === 1) {
                if ($pos + 16 > $n) {
                    break;
                }
                $largo = $this->uint64(substr($datos, $pos + 8, 8));
                $cabecera = 16;
            } elseif ($largo === 0) {
                $largo = $n - $pos;
            }
            if ($largo < $cabecera || $pos + $largo > $n) {
                break;
            }
            $out[] = [$tipo, substr($datos, $pos + $cabecera, $largo - $cabecera)];
            $pos += $largo;
        }
        return $out;
    }

    private function hija(string $datos, string $tipo): ?string
    {
        foreach ($this->cajas($datos) as [$t, $cuerpo]) {
            if ($t === $tipo) {
                return $cuerpo;
            }
        }
        return null;
    }

    private function analizarMoov(string $moov): ?array
    {
        $duracionTotal = null;
        if (($mvhd = $this->hija($moov, 'mvhd')) !== null) {
            [$escala, $dur] = $this->escalaYDuracion($mvhd, 12, 20);
            if ($escala > 0) {
                $duracionTotal = $dur / $escala;
            }
        }

        foreach ($this->cajas($moov) as [$tipo, $trak]) {
            if ($tipo !== 'trak') {
                continue;
            }
            $mdia = $this->hija($trak, 'mdia');
            $hdlr = $mdia !== null ? $this->hija($mdia, 'hdlr') : null;
            if ($hdlr === null || strlen($hdlr) < 12 || substr($hdlr, 8, 4) !== 'vide') {
                continue;
            }

            $minf = $this->hija($mdia, 'minf');
            $stbl = $minf !== null ? $this->hija($minf, 'stbl') : null;
            $stsd = $stbl !== null ? $this->hija($stbl, 'stsd') : null;
            // stsd: versión/flags(4) + cantidad(4) + la primera entrada:
            // largo(4) códec(4) reservado(6) índice(2) predefinido(16) ancho(2) alto(2).
            if ($stsd === null || strlen($stsd) < 8 + 36) {
                return null;
            }
            $codec = substr($stsd, 12, 4);
            $ancho = unpack('n', substr($stsd, 8 + 32, 2))[1];
            $alto  = unpack('n', substr($stsd, 8 + 34, 2))[1];

            $duracion = $duracionTotal;
            $fps = null;
            if (($mdhd = $this->hija($mdia, 'mdhd')) !== null) {
                [$escala, $dur] = $this->escalaYDuracion($mdhd, 12, 20);
                if ($escala > 0 && $dur > 0) {
                    $duracion = $duracion ?: $dur / $escala;
                    $cuadros = ($stts = $this->hija($stbl, 'stts')) !== null ? $this->cuadros($stts) : 0;
                    if ($cuadros > 0) {
                        $fps = $cuadros / ($dur / $escala);
                    }
                }
            }

            return [
                'duracion' => (float) ($duracion ?? 0),
                'ancho'    => $ancho,
                'alto'     => $alto,
                'codec'    => $codec,
                'fps'      => $fps,
            ];
        }

        return null;   // sin pista de video: un MP4 de sólo audio, por ejemplo
    }

    /**
     * Escala de tiempo y duración de mvhd/mdhd. Versión 0: campos de 32 bits a
     * partir del byte 12; versión 1: fechas de 64 bits, la escala en el 20.
     *
     * @return array{0:int,1:float}
     */
    private function escalaYDuracion(string $caja, int $offV0, int $offV1): array
    {
        if ($caja === '') {
            return [0, 0.0];
        }
        if (ord($caja[0]) === 1) {
            if (strlen($caja) < $offV1 + 12) {
                return [0, 0.0];
            }
            return [unpack('N', substr($caja, $offV1, 4))[1], (float) $this->uint64(substr($caja, $offV1 + 4, 8))];
        }
        if (strlen($caja) < $offV0 + 8) {
            return [0, 0.0];
        }
        return [unpack('N', substr($caja, $offV0, 4))[1], (float) unpack('N', substr($caja, $offV0 + 4, 4))[1]];
    }

    /** Total de cuadros según stts: suma de las cantidades de cada tramo. */
    private function cuadros(string $stts): int
    {
        if (strlen($stts) < 8) {
            return 0;
        }
        $entradas = unpack('N', substr($stts, 4, 4))[1];
        $total = 0;
        for ($i = 0; $i < $entradas; $i++) {
            $off = 8 + $i * 8;
            if ($off + 8 > strlen($stts)) {
                break;
            }
            $total += unpack('N', substr($stts, $off, 4))[1];
        }
        return $total;
    }

    private function uint64(string $b): int
    {
        $p = unpack('Nalto/Nbajo', $b);
        return ($p['alto'] << 32) | $p['bajo'];
    }
}
