<?php
/**
 * Smoke del CMS: pide por HTTP, a través del Apache real, TODAS las rutas GET
 * del panel (listados y modales Ver/Nuevo/Editar/Ítems/Energía/Telemetría)
 * con ids reales de la base, y falla si alguna no responde bien.
 *
 * "Bien" es: HTTP 200; los modales (pedidos como XHR) devuelven JSON válido
 * con `html`; ninguna respuesta trae la página de excepción de Symfony.
 * Además comprueba que la API de la Pi rechace un pedido sin API key.
 *
 * Uso:  php tools/verificacion/smoke.php [--base=http://intranet] [--sesion=<PHPSESSID>]
 * Sale con código 1 si hubo algún fallo.
 */

declare(strict_types=1);

require __DIR__ . '/lib/kernel.php';
require __DIR__ . '/lib/http.php';

$opts   = getopt('', ['base::', 'sesion::']);
$base   = rtrim($opts['base'] ?? 'http://intranet', '/');
$kernel = spuiKernel();
$sesion = $opts['sesion'] ?? spuiNuevaSesion();
$con    = $kernel->getContainer()->get('doctrine')->getConnection('spui');

/** Un id real para cada tipo de parámetro; null si la base no tiene ninguno. */
$uno = fn(string $sql): ?int => ($v = $con->fetchOne($sql)) === false ? null : (int) $v;
$ids = [
    'alertas'        => $uno('SELECT id FROM alerta_emergencia ORDER BY id DESC LIMIT 1'),
    'alerta_sonido'  => $uno('SELECT id FROM alerta_emergencia WHERE sonido_archivo IS NOT NULL ORDER BY id DESC LIMIT 1'),
    'contenidos'     => $uno("SELECT id FROM contenido WHERE tipo <> 'cronograma' ORDER BY id DESC LIMIT 1"),
    'contenido_arch' => $uno('SELECT id FROM contenido WHERE ruta_archivo IS NOT NULL ORDER BY id DESC LIMIT 1'),
    // De preferencia uno con ítems, para poder probar también la edición de un ítem.
    'cronograma'     => $uno("SELECT c.id FROM contenido c LEFT JOIN cronograma_item i ON i.contenido_id = c.id
                              WHERE c.tipo = 'cronograma' GROUP BY c.id ORDER BY COUNT(i.id) > 0 DESC, c.id DESC LIMIT 1"),
    'edificios'      => $uno('SELECT id FROM edificio ORDER BY id DESC LIMIT 1'),
    'pantallas'      => $uno('SELECT id FROM pantalla ORDER BY id DESC LIMIT 1'),
    'playlists'      => $uno('SELECT id FROM playlist ORDER BY id DESC LIMIT 1'),
    'programacion'   => $uno('SELECT id FROM programacion ORDER BY id DESC LIMIT 1'),
    'reproductores'  => $uno('SELECT id FROM reproductor ORDER BY id DESC LIMIT 1'),
    'ubicaciones'    => $uno('SELECT id FROM ubicacion ORDER BY id DESC LIMIT 1'),
];
$ids['cronograma_item'] = $ids['cronograma'] === null ? null
    : $uno('SELECT id FROM cronograma_item WHERE contenido_id = ' . $ids['cronograma'] . ' ORDER BY id LIMIT 1');

/** Resuelve los parámetros de una ruta a partir de su path; null = no hay datos para probarla. */
function resolverParametros(string $path, array $ids): ?array
{
    $p = [];
    if (str_contains($path, '{dia}')) {
        $p['dia'] = 1;
    }
    if (str_contains($path, '{itemId}')) {
        if ($ids['cronograma_item'] === null) return null;
        $p['itemId'] = $ids['cronograma_item'];
    }
    if (!str_contains($path, '{id}')) {
        return $p;
    }
    $clave = match (true) {
        str_ends_with($path, '/sonido-preview')  => 'alerta_sonido',
        str_ends_with($path, '/archivo-preview') => 'contenido_arch',
        str_contains($path, '/cronograma')       => 'cronograma',
        default => preg_match('#^/spui/([a-z]+)/\{id\}#', $path, $m) ? $m[1] : null,
    };
    if ($clave === null || !array_key_exists($clave, $ids)) {
        throw new RuntimeException("No sé con qué id probar $path: agregalo a resolverParametros().");
    }
    if ($ids[$clave] === null) return null;
    $p['id'] = $ids[$clave];
    return $p;
}

/** Rutas que no son pantallas ni modales: archivos binarios o sólo navegación. */
const NO_HTML = ['spui_cms_alertas_sonido_preview', 'spui_cms_contenidos_archivo_preview'];

$router  = $kernel->getContainer()->get('router');
$fallos  = [];
$ok      = 0;
$omitidas = [];

foreach ($router->getRouteCollection()->all() as $nombre => $ruta) {
    if (!str_starts_with($nombre, 'spui_cms_')) continue;
    $metodos = $ruta->getMethods();
    if ($metodos && !in_array('GET', $metodos, true)) continue;

    $params = resolverParametros($ruta->getPath(), $ids);
    if ($params === null) { $omitidas[] = "$nombre (sin datos en la base)"; continue; }
    $url = $base . $router->generate($nombre, $params);

    // Las páginas se piden como navegación; las acciones que existen para un
    // modal (ver, nuevo, editar, ítems, energía) se piden también como XHR.
    $esModal = (bool) preg_match('#/(ver|nuevo|nueva|nueva-regla|editar|items|cronograma|energia|agregar)(/|$)#', $ruta->getPath())
        || str_contains($nombre, '_items') || str_contains($nombre, '_energia_') || str_contains($nombre, '_cronograma_');

    foreach ($esModal ? [true] : [false] as $xhr) {
        $r = spuiHttp('GET', $url, $sesion, $xhr);
        $problema = spuiProblemaDeRespuesta($r, $xhr, in_array($nombre, NO_HTML, true));
        $etiqueta = sprintf('%-48s %s%s', $nombre, $xhr ? '[XHR] ' : '', substr($url, strlen($base)));
        if ($problema === null) { $ok++; echo "  OK    $etiqueta\n"; }
        else { $fallos[] = "$etiqueta → $problema"; echo "  FALLA $etiqueta → $problema\n"; }
    }
}

// La API de la Pi tiene que rechazar un pedido sin credenciales (CP09).
$r = spuiHttp('POST', $base . '/api/spui/reproductores/sync', null, false);
if ($r['status'] !== 401) { $fallos[] = "POST /api/spui/reproductores/sync sin X-Api-Key → {$r['status']} (se esperaba 401)"; }
else { $ok++; echo "  OK    API sin X-Api-Key → 401\n"; }

foreach ($omitidas as $o) echo "  OMITE $o\n";
printf("\nSmoke: %d OK, %d fallas, %d omitidas.\n", $ok, count($fallos), count($omitidas));
foreach ($fallos as $f) echo "  ✗ $f\n";
exit($fallos ? 1 : 0);
