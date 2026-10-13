<?php
/**
 * ¿El navegador está corriendo el JS/CSS actual de SPUI, o uno viejo?
 *
 * Mientras exista `public/assets/manifest.json` (build de AssetMapper,
 * gitignorado y compartido por todo el monorepo), `asset()` sirve los archivos
 * compilados aunque estemos en dev, y Apache los entrega directo: una
 * modificación en `assets/spui/` no se ve hasta regenerar el build. Ya hubo una
 * ronda entera de pruebas manuales con JS viejo por esto (docs/README.md §3.4).
 *
 * Compara cada asset de SPUI que figura en el manifiesto con su fuente:
 * JS exacto; CSS sin tener en cuenta los url(...), que el build reescribe.
 *
 * Uso: php tools/verificacion/assets.php   (sale con 1 si hay assets viejos)
 */

declare(strict_types=1);

const RAIZ   = __DIR__ . '/../../../..';            // c:/wamp64/www/intranet
const FUENTE = __DIR__ . '/../../assets/';          // apps/spui/assets/

$manifiesto = RAIZ . '/public/assets/manifest.json';
if (!is_file($manifiesto)) {
    echo "Assets: no hay build compilado; dev sirve las fuentes directamente. OK.\n";
    exit(0);
}

$normalizar = function (string $contenido, string $ext): string {
    $contenido = str_replace("\r\n", "\n", $contenido);
    if ($ext === 'css') {
        $contenido = preg_replace('/url\(\s*([\'"]?)[^)\'"]*\1\s*\)/', 'url()', $contenido);
    }
    return $contenido;
};

$viejos = [];
$faltan = [];
$revisados = 0;
foreach (json_decode((string) file_get_contents($manifiesto), true) as $logico => $publico) {
    $fuente = FUENTE . $logico;
    if (!str_starts_with($logico, 'spui/') || !is_file($fuente)) continue;
    $ext = strtolower(pathinfo($logico, PATHINFO_EXTENSION));
    if (!in_array($ext, ['js', 'css'], true)) continue;

    $revisados++;
    $compilado = RAIZ . '/public' . $publico;
    if (!is_file($compilado)) { $faltan[] = $logico; continue; }
    if ($normalizar((string) file_get_contents($fuente), $ext) !== $normalizar((string) file_get_contents($compilado), $ext)) {
        $viejos[] = $logico;
    }
}

// Archivos nuevos que todavía no están en el manifiesto: esos sí salen
// actuales, pero conviene saberlo porque el resto puede no estarlo.
$nuevos = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FUENTE . 'spui', FilesystemIterator::SKIP_DOTS));
$enManifiesto = array_flip(array_keys(json_decode((string) file_get_contents($manifiesto), true)));
foreach ($it as $f) {
    $logico = 'spui/' . str_replace('\\', '/', substr($f->getPathname(), strlen(FUENTE . 'spui/')));
    if (in_array(strtolower($f->getExtension()), ['js', 'css'], true) && !isset($enManifiesto[$logico])) $nuevos[] = $logico;
}

printf("Assets: %d archivos de SPUI revisados contra el build compilado.\n", $revisados);
foreach ($viejos as $v) echo "  VIEJO  $v (el build tiene otra versión)\n";
foreach ($faltan as $v) echo "  FALTA  $v (el manifiesto lo nombra pero el archivo compilado no existe)\n";
foreach ($nuevos as $v) echo "  NUEVO  $v (no está en el build: se sirve la fuente)\n";

if ($viejos || $faltan) {
    echo "\n  → Correr RebuildAssets.exe antes de probar en el navegador o sacar capturas.\n";
    exit(1);
}
echo "  Build al día.\n";
exit(0);
