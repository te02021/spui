<?php
/**
 * ImagenNormalizerService: cada imagen sale derecha y de 1920×1080 o menos.
 *
 * Orientación: se arma una imagen "derecha" con un color distinto en cada
 * esquina, se la guarda girada como la guardaría una cámara para cada una de
 * las 8 orientaciones EXIF, y se comprueba que salga derecha otra vez. Un
 * error acá deja las fotos de celular de costado o espejadas en la pantalla.
 *
 * Además: achicado (horizontal y vertical), transparencia → PNG, WebP → JPEG,
 * una imagen que ya sirve no se toca, basura y un tamaño imposible de abrir.
 * Origen del caso: una foto de 4608×3456 girada por EXIF cerraba VLC en la Pi
 * (24/10/2026).
 *
 * Uso: php tools/verificacion/imagen.php   (sale con 1 si algún caso falla)
 */

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use Psr\Log\NullLogger;
use SPUI\Service\ImagenNormalizerService;

$dir = sys_get_temp_dir() . '/spui-imagen-' . getmypid();
@mkdir($dir);
$norm = new ImagenNormalizerService(new NullLogger());
$fallas = 0;

function informar(bool $ok, string $nombre, string $detalle): void
{
    global $fallas;
    $fallas += $ok ? 0 : 1;
    printf("  %-5s %-40s %s\n", $ok ? 'OK' : 'FALLA', $nombre, $detalle);
}

/** Imagen derecha de 60×30: rojo arriba a la izquierda, verde arriba a la derecha, azul abajo a la izquierda, blanco abajo a la derecha. */
function derecha(): GdImage
{
    $img = imagecreatetruecolor(60, 30);
    imagefilledrectangle($img, 0, 0, 29, 14, imagecolorallocate($img, 255, 0, 0));
    imagefilledrectangle($img, 30, 0, 59, 14, imagecolorallocate($img, 0, 255, 0));
    imagefilledrectangle($img, 0, 15, 29, 29, imagecolorallocate($img, 0, 0, 255));
    imagefilledrectangle($img, 30, 15, 59, 29, imagecolorallocate($img, 255, 255, 255));
    return $img;
}

/** Cómo la guarda la cámara para que, aplicando la orientación $o, se vea derecha. */
function comoLaGuardaLaCamara(GdImage $u, int $o): GdImage
{
    $copia = imagecrop($u, ['x' => 0, 'y' => 0, 'width' => imagesx($u), 'height' => imagesy($u)]);
    switch ($o) {
        case 2: imageflip($copia, IMG_FLIP_HORIZONTAL); return $copia;
        case 3: return imagerotate($copia, 180, 0);
        case 4: imageflip($copia, IMG_FLIP_VERTICAL); return $copia;
        case 5: imageflip($copia, IMG_FLIP_HORIZONTAL); return imagerotate($copia, 90, 0);    // transpuesta
        case 6: return imagerotate($copia, 90, 0);                                              // la cámara giró 90° antihorario
        case 7: imageflip($copia, IMG_FLIP_HORIZONTAL); return imagerotate($copia, -90, 0);   // transversa
        case 8: return imagerotate($copia, -90, 0);
        default: return $copia;
    }
}

/** JPEG con un segmento EXIF que sólo trae la orientación. */
function jpegConOrientacion(GdImage $img, int $o, string $ruta): void
{
    ob_start();
    imagejpeg($img, null, 95);
    $jpeg = ob_get_clean();
    $tiff = "MM\x00\x2a" . pack('N', 8) . pack('n', 1)
          . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', $o) . "\x00\x00"
          . pack('N', 0);
    $app1 = "Exif\x00\x00" . $tiff;
    file_put_contents($ruta, "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2));
}

function colorEn(string $ruta, int $x, int $y): string
{
    $img = imagecreatefromstring((string) file_get_contents($ruta));
    $c = imagecolorat($img, $x, $y);
    $rgb = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    if (min($rgb) > 200) return 'blanco';
    $max = array_keys($rgb, max($rgb))[0];
    return ['rojo', 'verde', 'azul'][$max];
}

// ── Las 8 orientaciones EXIF ────────────────────────────────────────────────
$u = derecha();
for ($o = 1; $o <= 8; $o++) {
    $ruta = "$dir/o$o.jpg";
    jpegConOrientacion(comoLaGuardaLaCamara($u, $o), $o, $ruta);
    $r = $norm->normalizar($ruta);
    if (isset($r['error'])) { informar(false, "orientación EXIF $o", $r['error']); continue; }
    $esquinas = [colorEn($r['ruta'], 5, 3), colorEn($r['ruta'], 54, 3), colorEn($r['ruta'], 5, 26), colorEn($r['ruta'], 54, 26)];
    $ok = $r['ancho'] === 60 && $r['alto'] === 30 && $esquinas === ['rojo', 'verde', 'azul', 'blanco'];
    informar($ok, "orientación EXIF $o", "{$r['ancho']}×{$r['alto']}, esquinas " . implode('/', $esquinas));
}

// ── Tamaño y formato ────────────────────────────────────────────────────────
$casos = [
    // [nombre, generador → ruta, ancho esperado, alto esperado, extensión esperada, ¿cambia?]
    ['foto horizontal de 4608×3456',  fn () => guardar(imagecreatetruecolor(4608, 3456), 'jpg'), 1440, 1080, 'jpg', true],
    ['foto vertical de 3456×4608',    fn () => guardar(imagecreatetruecolor(3456, 4608), 'jpg'), 1080, 1440, 'jpg', true],
    ['3840×2160',                      fn () => guardar(imagecreatetruecolor(3840, 2160), 'png'), 1920, 1080, 'jpg', true],
    ['PNG transparente de 2400×1200', fn () => guardar(transparente(2400, 1200), 'png'),        1920, 960,  'png', true],
    ['WebP chica',                     fn () => guardar(imagecreatetruecolor(800, 600), 'webp'),  800,  600,  'jpg', true],
    ['JPEG de 1920×1080 derecho',     fn () => guardar(imagecreatetruecolor(1920, 1080), 'jpg'), 1920, 1080, 'jpg', false],
];

function guardar(GdImage $img, string $ext): string
{
    global $dir;
    $ruta = "$dir/" . uniqid() . ".$ext";
    match ($ext) { 'jpg' => imagejpeg($img, $ruta, 90), 'png' => imagepng($img, $ruta), 'webp' => imagewebp($img, $ruta) };
    imagedestroy($img);
    return $ruta;
}

function transparente(int $w, int $h): GdImage
{
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    return $img;
}

foreach ($casos as [$nombre, $gen, $w, $h, $ext, $cambia]) {
    $r = $norm->normalizar($gen());
    if (isset($r['error'])) { informar(false, $nombre, $r['error']); continue; }
    $real = getimagesize($r['ruta']);
    $ok = $r['ancho'] === $w && $r['alto'] === $h && $real[0] === $w && $real[1] === $h && $r['extension'] === $ext && $r['cambio'] === $cambia;
    informar($ok, $nombre, "{$r['ancho']}×{$r['alto']} .{$r['extension']}" . ($r['cambio'] ? '' : ' (sin tocar)'));
}

// ── Lo que no se puede procesar ─────────────────────────────────────────────
file_put_contents("$dir/basura.jpg", str_repeat('no soy una imagen ', 100));
$r = $norm->normalizar("$dir/basura.jpg");
informar(isset($r['error']), 'archivo que no es una imagen', $r['error'] ?? 'LA ACEPTÓ');

// PNG de 10000×6000 (60 MP): sólo la cabecera; getimagesize no necesita más.
file_put_contents("$dir/enorme.png", "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', 10000) . pack('N', 6000) . "\x08\x02\x00\x00\x00" . pack('N', 0));
$r = $norm->normalizar("$dir/enorme.png");
informar(isset($r['error']) && str_contains($r['error'], '10000×6000'), 'imagen de 60 megapíxeles', $r['error'] ?? 'LA ACEPTÓ');

array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);
echo $fallas === 0 ? "Imagen: todos los casos dan lo esperado.\n" : "Imagen: $fallas caso(s) fallaron.\n";
exit($fallas === 0 ? 0 : 1);
