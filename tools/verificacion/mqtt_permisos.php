<?php
/**
 * Permisos MQTT por equipo (29/10/2026): un reproductor sólo puede publicar
 * en sus propios topics y sólo recibe sus propios comandos.
 *
 * Contra el broker real: le asegura su rol propio al primer reproductor de la
 * base (lo mismo que hace cada sync), crea un cliente de prueba TEMPORAL con
 * ese mismo rol, prueba cada permiso y lo borra al terminar. No toca la
 * credencial del reproductor ni publica nada que se guarde o que una pantalla
 * muestre: el control positivo va a spui/telemetria/vivo/{id}, que el daemon
 * no guarda (una prueba en spui/telemetria/{id} dejó una lectura en cero en
 * la base, 29/10/2026), y lo que podría llegar a un reproductor va a un id
 * que no existe.
 *
 * Uso: php tools/verificacion/mqtt_permisos.php   (sale con 1 si algo falla,
 * con 2 si no se pudo probar: broker caído o sin reproductores)
 */

declare(strict_types=1);

use PhpMqtt\Client\MqttClient;
use Psr\Log\NullLogger;
use SPUI\Entity\Reproductor;
use SPUI\Service\MqttConnectionFactory;
use SPUI\Service\MqttSecurityService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

require __DIR__ . '/lib/kernel.php';

$kernel = spuiKernel();
$env = static fn(string $k, string $d = ''): string => (string) ($_SERVER[$k] ?? $_ENV[$k] ?? getenv($k) ?: $d);

$host  = $env('MQTT_HOST', '127.0.0.1');
$port  = (int) $env('MQTT_PORT', '1883');
$tls   = filter_var($env('MQTT_TLS', 'false'), FILTER_VALIDATE_BOOL);
$fab   = new MqttConnectionFactory($tls, $env('MQTT_TLS_CA_FILE'));
$seg   = new MqttSecurityService(new NullLogger(), $fab, new ArrayAdapter(), $host, $port,
    $env('MQTT_ADMIN_USER'), $env('MQTT_ADMIN_PASS'), $env('SPUI_MQTT_SALT'));

/** @var Reproductor|null $repro */
$repro = $kernel->getContainer()->get('doctrine')->getManager('SPUI')->getRepository(Reproductor::class)->findOneBy([], ['id' => 'ASC']);
if ($repro === null) {
    echo "MQTT permisos: no hay reproductores para probar.\n";
    exit(2);
}
$a = $repro->getId();
$b = 900000 + $a;   // un id que no existe: lo que "llegue" ahí no lo muestra ninguna pantalla

function conectar(MqttConnectionFactory $fab, string $host, int $port, string $usuario, string $clave): MqttClient
{
    $c = new MqttClient($host, $port, 'spui-verif-' . bin2hex(random_bytes(4)));
    $c->connect($fab->paraCredenciales($usuario, $clave)->setConnectTimeout(5)->setSocketTimeout(5), true);
    return $c;
}

/** Atiende los clientes durante $seg segundos o hasta que $listo() dé true. */
function esperar(array $clientes, float $seg, callable $listo): void
{
    $fin = microtime(true) + $seg;
    while (microtime(true) < $fin && !$listo()) {
        foreach ($clientes as $c) { $c->loopOnce(microtime(true), true); }
        usleep(20000);
    }
}

// Rol propio del reproductor (como en cada sync) y cliente temporal con ese rol.
$usuarioPrueba = 'spui-verif-acl-' . bin2hex(random_bytes(3));
$clavePrueba   = bin2hex(random_bytes(16));
$admin = function (array $comandos) use ($fab, $host, $port, $env): void {
    $c = conectar($fab, $host, $port, $env('MQTT_ADMIN_USER'), $env('MQTT_ADMIN_PASS'));
    $c->publish('$CONTROL/dynamic-security/v1', json_encode(['commands' => $comandos]), MqttClient::QOS_AT_LEAST_ONCE);
    $c->disconnect();
};

$fallas = 0;
$casos = 0;
$ver = static function (string $nombre, string $esperado, string $obtenido) use (&$fallas, &$casos): void {
    $casos++;
    $ok = $esperado === $obtenido;
    $fallas += $ok ? 0 : 1;
    printf("  %-5s %-66s %s\n", $ok ? 'OK' : 'FALLA', $nombre, $obtenido);
};

try {
    if (!$seg->asegurarPermisos($repro)) {
        throw new RuntimeException('el broker no aceptó el alta del rol');
    }
    $admin([
        ['command' => 'createClient', 'username' => $usuarioPrueba, 'password' => $clavePrueba],
        ['command' => 'addClientRole', 'username' => $usuarioPrueba, 'rolename' => MqttSecurityService::rolDe($repro)],
    ]);
    usleep(300000);

    $equipo = conectar($fab, $host, $port, $usuarioPrueba, $clavePrueba);
    $cms    = conectar($fab, $host, $port, $env('MQTT_USER'), $env('MQTT_PASS'));

    // Lo que publica "el equipo": ¿le llega al CMS?
    $recibidoCms = [];
    $anotar = function (string $t, string $m) use (&$recibidoCms) {
        if (preg_match('/"prueba":"(verif-[0-9a-f]+)"/', $m, $x)) { $recibidoCms[$x[1]] = $t; }
    };
    // Topics exactos y no spui/estado/#: ese comodín trae cientos de "online"
    // retenidos (de los reproductores simulados de las pruebas de carga) y la
    // avalancha atrasaba el mensaje de la prueba más allá de la espera.
    foreach (["spui/telemetria/vivo/$a", "spui/telemetria/$b", "spui/telemetria/vivo/$b", "spui/estado/reproductor/$b"] as $topic) {
        $cms->subscribe($topic, $anotar, 0);
    }
    esperar([$cms, $equipo], 0.6, fn() => false);

    $publica = function (string $topic) use ($equipo, $cms, &$recibidoCms): string {
        $marca = 'verif-' . bin2hex(random_bytes(4));
        $equipo->publish($topic, json_encode(['prueba' => $marca]), 0);
        esperar([$equipo, $cms], 2.0, fn() => isset($recibidoCms[$marca]));
        return isset($recibidoCms[$marca]) ? 'llegó' : 'no llegó';
    };
    $ver("Publica su telemetría en vivo (spui/telemetria/vivo/$a) — control", 'llegó', $publica("spui/telemetria/vivo/$a"));
    $ver("Publica telemetría de otro equipo (spui/telemetria/$b)", 'no llegó', $publica("spui/telemetria/$b"));
    $ver("Publica telemetría en vivo de otro equipo (spui/telemetria/vivo/$b)", 'no llegó', $publica("spui/telemetria/vivo/$b"));
    $ver("Publica el estado de otro equipo (spui/estado/reproductor/$b)", 'no llegó', $publica("spui/estado/reproductor/$b"));

    // Alerta: el equipo se suscribe a la global (puede) y trata de publicarla.
    $alertaPropia = null;
    $equipo->subscribe('spui/alertas/emergencia', function (string $t, string $m) use (&$alertaPropia) {
        if (str_contains($m, 'verif-alerta')) { $alertaPropia = $m; }
    }, 0);
    esperar([$equipo], 0.5, fn() => false);
    $equipo->publish('spui/alertas/emergencia', json_encode(['accion' => 'verif-alerta']), 0);
    esperar([$equipo], 2.0, fn() => $alertaPropia !== null);
    $ver('Publica una alerta de emergencia', 'no llegó', $alertaPropia !== null ? 'llegó' : 'no llegó');

    // Comandos: los propios le llegan; los de otro equipo, no.
    $comandos = [];
    $equipo->subscribe("spui/comandos/reproductor/$a", function (string $t, string $m) use (&$comandos) { $comandos[$t] = $m; }, 0);
    $equipo->subscribe("spui/comandos/reproductor/$b", function (string $t, string $m) use (&$comandos) { $comandos[$t] = $m; }, 0);
    esperar([$equipo], 0.6, fn() => false);
    // Un comando que la Pi ignora ("verificacion" no es una acción conocida).
    $cms->publish("spui/comandos/reproductor/$a", json_encode(['accion' => 'verificacion']), 0);
    $cms->publish("spui/comandos/reproductor/$b", json_encode(['accion' => 'verificacion']), 0);
    esperar([$equipo, $cms], 2.0, fn() => count($comandos) >= 2);
    $ver("Recibe sus comandos (spui/comandos/reproductor/$a) — control", 'llegó', isset($comandos["spui/comandos/reproductor/$a"]) ? 'llegó' : 'no llegó');
    $ver("Recibe comandos de otro equipo (spui/comandos/reproductor/$b)", 'no llegó', isset($comandos["spui/comandos/reproductor/$b"]) ? 'llegó' : 'no llegó');

    $equipo->disconnect();
    $cms->disconnect();
} catch (Throwable $e) {
    echo "MQTT permisos: no se pudo probar ({$e->getMessage()}). ¿Mosquitto corriendo en $host:$port?\n";
    exit(2);
} finally {
    try { $admin([['command' => 'deleteClient', 'username' => $usuarioPrueba]]); } catch (Throwable) { /* sin broker no quedó nada */ }
}

echo $fallas === 0
    ? "MQTT permisos: los $casos casos dan lo esperado (reproductor $a, rol " . MqttSecurityService::rolDe($repro) . ").\n"
    : "MQTT permisos: $fallas de $casos casos fallaron.\n";
exit($fallas === 0 ? 0 : 1);
