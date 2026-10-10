<?php
/**
 * Genera una sesión iniciada del CMS sin pasar por el login de Google, para
 * que el navegador automatizado (capturas.mjs) pueda recorrer el panel.
 *
 * Escribe una sesión nativa de PHP en el mismo session.save_path que usa
 * Apache (en este WAMP el CLI y Apache leen el mismo php.ini; ver
 * docs/README.md §3, hecho 15) con el token de seguridad guardado bajo
 * `_security_main`, que es donde lo busca el firewall `main` en cada request.
 *
 * Salida: el id de sesión (para la cookie PHPSESSID) en la última línea.
 * Uso:    php tools/verificacion/sesion.php
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

require __DIR__ . '/lib/kernel.php';

$kernel = spuiKernel();
$user   = spuiUsuarioVerificacion($kernel);

$session = new Session(new NativeSessionStorage(['cookie_samesite' => 'lax']));
$session->start();
$session->set('_security_main', spuiTokenSerializado($user));
$id = $session->getId();
$session->save();

// Comprobación: el archivo tiene que existir donde lo va a buscar Apache.
$archivo = rtrim(str_replace('\\', '/', (string) ini_get('session.save_path')), '/') . '/sess_' . $id;
if (!is_file($archivo)) {
    fwrite(STDERR, "No se escribió la sesión en $archivo\n");
    exit(1);
}

echo $id, "\n";
