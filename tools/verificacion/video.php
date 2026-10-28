<?php
/**
 * VideoInspectorService: qué videos acepta el CMS y cuánto dicen que duran.
 *
 * Arma MP4 sintéticos con las mismas cajas que un archivo real (moov al final,
 * cajas de 64 bits, versión 1 de mvhd/mdhd, otros códecs, 60 fps, vertical,
 * sólo audio, basura) y compara lo que dice el lector con lo esperado. Sin
 * esto, un cambio en el lector podría dejar entrar un 4K otra vez (la pantalla
 * trabada del 24/10/2026) o rechazar videos que la Pi reproduce bien.
 *
 * Uso: php tools/verificacion/video.php   (sale con 1 si algún caso falla)
 */

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use SPUI\Service\VideoInspectorService;

function caja(string $tipo, string $cuerpo): string
{
    return pack('N', 8 + strlen($cuerpo)) . $tipo . $cuerpo;
}

/** mvhd/mdhd: versión 0 (campos de 32 bits) o 1 (fechas y duración de 64 bits). */
function cabeceraTiempo(string $tipo, int $version, int $escala, int $duracion): string
{
    $cuerpo = $version === 1
        ? chr(1) . "\0\0\0" . str_repeat("\0", 16) . pack('N', $escala) . pack('J', $duracion)
        : "\0\0\0\0" . str_repeat("\0", 8) . pack('N', $escala) . pack('N', $duracion);
    return caja($tipo, $cuerpo . str_repeat("\0", 80));
}

function pista(string $manejador, string $codec, int $ancho, int $alto, int $escala, int $duracion, int $cuadros, int $version): string
{
    $hdlr = caja('hdlr', "\0\0\0\0" . "\0\0\0\0" . $manejador . str_repeat("\0", 13));
    $entrada = $codec . str_repeat("\0", 6) . pack('n', 1) . str_repeat("\0", 16) . pack('n', $ancho) . pack('n', $alto) . str_repeat("\0", 50);
    $stsd = caja('stsd', "\0\0\0\0" . pack('N', 1) . pack('N', 4 + strlen($entrada)) . $entrada);
    $stts = caja('stts', "\0\0\0\0" . pack('N', 1) . pack('N', $cuadros) . pack('N', intdiv($duracion, max(1, $cuadros))));
    $stbl = caja('stbl', $stsd . $stts);
    return caja('trak', caja('mdia', cabeceraTiempo('mdhd', $version, $escala, $duracion) . $hdlr . caja('minf', $stbl)));
}

/**
 * @param array{codec?:string,ancho?:int,alto?:int,seg?:float,fps?:int,version?:int,manejador?:string,mdatGrande?:bool} $o
 */
function mp4(array $o): string
{
    $escala = 12800;
    $seg    = $o['seg'] ?? 22.3;
    $fps    = $o['fps'] ?? 24;
    $dur    = (int) round($seg * $escala);
    $v      = $o['version'] ?? 0;
    $moov = caja('moov',
        cabeceraTiempo('mvhd', $v, 1000, (int) round($seg * 1000))
        . pista($o['manejador'] ?? 'vide', $o['codec'] ?? 'avc1', $o['ancho'] ?? 1920, $o['alto'] ?? 1080, $escala, $dur, (int) round($seg * $fps), $v)
    );
    $datos = str_repeat("\x55", 4096);
    $mdat = ($o['mdatGrande'] ?? false)
        ? pack('N', 1) . 'mdat' . pack('J', 16 + strlen($datos)) . $datos   // caja de 64 bits
        : caja('mdat', $datos);
    // moov AL FINAL, como lo deja la mayoría de los editores: hay que saltar mdat.
    return caja('ftyp', 'isom' . pack('N', 512) . 'isomavc1') . $mdat . $moov;
}

$insp = new VideoInspectorService();
$dir  = sys_get_temp_dir() . '/spui-video-' . getmypid();
@mkdir($dir);

// [nombre, bytes del archivo, ¿se acepta?, segundos esperados o texto que tiene que tener el rechazo]
$casos = [
    ['1080p H.264 24 fps (moov al final)', mp4([]),                                      true,  23],
    ['720p H.264 30 fps',                  mp4(['ancho' => 1280, 'alto' => 720, 'fps' => 30, 'seg' => 8]), true, 8],
    ['vertical 1080×1920',                 mp4(['ancho' => 1080, 'alto' => 1920]),       true,  23],
    ['mvhd/mdhd versión 1',                mp4(['version' => 1, 'seg' => 125.5]),       true,  126],
    ['mdat con tamaño de 64 bits',         mp4(['mdatGrande' => true, 'seg' => 3]),      true,  3],
    ['4K 3840×2160',                       mp4(['ancho' => 3840, 'alto' => 2160]),       false, '3840×2160'],
    ['2560×1440',                          mp4(['ancho' => 2560, 'alto' => 1440]),       false, '2560×1440'],
    ['H.265 (HEVC)',                       mp4(['codec' => 'hvc1']),                     false, 'H.265'],
    ['VP9 en MP4',                         mp4(['codec' => 'vp09']),                     false, 'VP9'],
    ['60 cuadros por segundo',             mp4(['fps' => 60]),                           false, '60 cuadros'],
    ['sólo audio, sin pista de video',     mp4(['manejador' => 'soun']),                 false, 'No se pudo leer'],
    ['archivo que no es un video',         str_repeat('no soy un video ', 200),          false, 'No se pudo leer'],
    ['archivo vacío',                      '',                                           false, 'No se pudo leer'],
];

$fallas = 0;
foreach ($casos as $i => [$nombre, $bytes, $acepta, $esperado]) {
    $ruta = "$dir/caso$i.mp4";
    file_put_contents($ruta, $bytes);
    $info   = $insp->inspeccionar($ruta);
    $motivo = $insp->motivoRechazo($info);

    if ($acepta) {
        $seg = $motivo === null ? VideoInspectorService::segundos($info) : null;
        $ok  = $motivo === null && $seg === $esperado;
        $det = $motivo === null ? "acepta, $seg s" : "rechaza: $motivo";
    } else {
        $ok  = $motivo !== null && str_contains($motivo, $esperado);
        $det = $motivo === null ? 'ACEPTA' : "rechaza: $motivo";
    }
    $fallas += $ok ? 0 : 1;
    printf("  %-5s %-36s %s\n", $ok ? 'OK' : 'FALLA', $nombre, $det);
    @unlink($ruta);
}
@rmdir($dir);

// ── Links de YouTube (SPUI\Service\YoutubeUrl) ──────────────────────────────
// Toda forma en que se copia un link termina en el mismo watch?v=<id>, que es
// lo que la Pi descarga y el nombre con que lo guarda.
$links = [
    'https://www.youtube.com/watch?v=aqz-KE-bpKQ'                      => 'aqz-KE-bpKQ',
    'https://youtube.com/watch?v=aqz-KE-bpKQ&t=42s&list=PL123'         => 'aqz-KE-bpKQ',
    'https://m.youtube.com/watch?feature=share&v=aqz-KE-bpKQ'          => 'aqz-KE-bpKQ',
    'https://youtu.be/aqz-KE-bpKQ?si=abc123'                           => 'aqz-KE-bpKQ',
    'youtu.be/aqz-KE-bpKQ'                                             => 'aqz-KE-bpKQ',
    'https://www.youtube.com/shorts/aqz-KE-bpKQ'                       => 'aqz-KE-bpKQ',
    'https://www.youtube.com/embed/aqz-KE-bpKQ?autoplay=1'             => 'aqz-KE-bpKQ',
    'https://www.youtube.com/live/aqz-KE-bpKQ'                         => 'aqz-KE-bpKQ',
    'https://www.youtube.com/watch?v=corto'                            => null,
    'https://www.youtube.com/channel/UCabcdefghijklmnopqrstuv'         => null,
    'https://vimeo.com/123456789'                                      => null,
    'https://www.youtube.com.evil.com/watch?v=aqz-KE-bpKQ'             => null,
    'un texto cualquiera'                                              => null,
];
foreach ($links as $link => $id) {
    $real = SPUI\Service\YoutubeUrl::id($link);
    $ok = $real === $id;
    $fallas += $ok ? 0 : 1;
    printf("  %-5s %-62s %s\n", $ok ? 'OK' : 'FALLA', $link, $real ?? 'no es de YouTube');
}
if (SPUI\Service\YoutubeUrl::normalizar('youtu.be/aqz-KE-bpKQ?si=x') !== 'https://www.youtube.com/watch?v=aqz-KE-bpKQ') {
    $fallas++;
    echo "  FALLA normalizar no deja el link como watch?v=<id>\n";
}

echo $fallas === 0 ? "Video: los " . count($casos) . " videos y los " . count($links) . " links de YouTube dan lo esperado.\n" : "Video: $fallas caso(s) fallaron.\n";
exit($fallas === 0 ? 0 : 1);
