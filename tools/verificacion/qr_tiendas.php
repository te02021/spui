<?php
/**
 * QR de una app (03/11/2026): un solo código con los enlaces de Google Play y
 * del App Store, y cada teléfono va a su tienda (CodigoQr::destinoPara).
 *
 * Lo que tiene que pasar, con User-Agents reales:
 *   - Android → Google Play; iPhone → App Store, en cualquier orden de carga.
 *   - iPad (Safari se presenta como una Mac), computadora o un lector que no
 *     se identifica → null: el CMS muestra la página con las dos tiendas.
 *   - Un QR de un solo destino (o dos enlaces que no son de tienda) → siempre
 *     ese, como antes.
 *
 * Sin base ni red: sólo la entidad. Uso: php tools/verificacion/qr_tiendas.php
 * (sale con 1 si algún caso falla).
 */

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use SPUI\Entity\CodigoQr;

const PLAY  = 'https://play.google.com/store/apps/details?id=com.unraf.pwa&hl=es_AR';
const APPLE = 'https://apps.apple.com/ar/app/unraf/id6755437105';
const WEB   = 'https://www.unraf.edu.ar/';

$agentes = [
    'Android'    => 'Mozilla/5.0 (Linux; Android 14; SM-A146M) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36',
    'iPhone'     => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
    'iPad viejo' => 'Mozilla/5.0 (iPad; CPU OS 12_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/12.1 Mobile/15E148 Safari/604.1',
    'iPad'       => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
    'PC'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
    'Sin agente' => '',
];

function qr(string $a, ?string $b = null): CodigoQr
{
    return (new CodigoQr())->setUrlDestino($a)->setUrlDestinoAlternativa($b);
}

$casos = [
    ['Play y App Store',   qr(PLAY, APPLE), ['Android' => PLAY, 'iPhone' => APPLE, 'iPad viejo' => APPLE, 'iPad' => null, 'PC' => null, 'Sin agente' => null]],
    ['App Store y Play',   qr(APPLE, PLAY), ['Android' => PLAY, 'iPhone' => APPLE, 'iPad viejo' => APPLE, 'iPad' => null, 'PC' => null, 'Sin agente' => null]],
    ['Un solo destino',    qr(WEB),         array_fill_keys(array_keys($agentes), WEB)],
    ['Sólo Google Play',   qr(PLAY),        array_fill_keys(array_keys($agentes), PLAY)],
];

$fallas = 0;
foreach ($casos as [$nombre, $codigo, $esperado]) {
    foreach ($esperado as $quien => $destino) {
        $obtenido = $codigo->destinoPara($agentes[$quien]);
        if ($obtenido !== $destino) {
            $fallas++;
            printf("  ✗ %s, %s: esperaba %s y dio %s\n", $nombre, $quien, $destino ?? 'la página de las dos tiendas', $obtenido ?? 'la página de las dos tiendas');
        }
    }
    if ($fallas === 0) {
        printf("  OK    %s\n", $nombre);
    }
}
foreach ([[PLAY, 'android'], [APPLE, 'ios'], ['https://itunes.apple.com/app/id1', 'ios'], [WEB, null]] as [$url, $tienda]) {
    if (CodigoQr::tiendaDe($url) !== $tienda) {
        $fallas++;
        printf("  ✗ tiendaDe(%s) no dio %s\n", $url, $tienda ?? 'null');
    }
}

printf("\nQR de app: %s.\n", $fallas ? "$fallas fallas" : 'todo bien');
exit($fallas ? 1 : 0);
