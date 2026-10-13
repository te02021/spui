<?php
/**
 * Datos de prueba del simulador de reproductores (lado CMS).
 *
 *   php tools/simulador/sim.php preparar <N> [--playlist=<id>]   crea la estructura [SIM] y escribe .sim/estado.json
 *   php tools/simulador/sim.php alerta                              crea (inactiva) una alerta dirigida a las pantallas [SIM]
 *   php tools/simulador/sim.php integridad <desde_iso>              revisa la base después de una corrida (JSON)
 *   php tools/simulador/sim.php limpiar                             borra TODO lo [SIM] y revoca sus credenciales MQTT
 *
 * Todo lo que crea lleva la marca [SIM] / sim-NNN y vive en un edificio propio,
 * así nunca se mezcla con los datos reales: las alertas van dirigidas sólo a
 * las pantallas [SIM], de modo que los reproductores reales no se enteran.
 * `limpiar` es idempotente y busca por esa marca, no por el estado.json: deja
 * la base limpia aunque una corrida haya quedado a medias.
 */

declare(strict_types=1);

use SPUI\Entity\AlertaEmergencia;
use SPUI\Entity\Edificio;
use SPUI\Entity\Pantalla;
use SPUI\Entity\Playlist;
use SPUI\Entity\Reproductor;
use SPUI\Entity\Ubicacion;

require __DIR__ . '/../verificacion/lib/kernel.php';

const EDIFICIO_SIM = '[SIM] Prueba de carga';
const ESTADO = __DIR__ . '/.sim/estado.json';

$kernel = spuiKernel();
$c      = $kernel->getContainer();
$em     = $c->get('doctrine')->getManager('SPUI');
$con    = $em->getConnection();
date_default_timezone_set('America/Argentina/Buenos_Aires');

// MqttSecurityService es privado en el contenedor: se toma del locator de un controller que lo usa.
function mqttSecurity(): SPUI\Service\MqttSecurityService
{
    global $c;
    $ctrl = $c->get(SPUI\Controller\Cms\ReproductorCmsController::class);
    $p = (new ReflectionObject($ctrl))->getProperty('mqttSecurity');
    return $p->getValue($ctrl);
}

function salida(array $datos): void
{
    echo json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

$accion = $argv[1] ?? '';

switch ($accion) {
    case 'preparar':
        $n = max(1, (int) ($argv[2] ?? 50));
        // getopt() deja de leer en el primer argumento suelto ("preparar"): se busca a mano.
        $opts = [];
        foreach ($argv as $arg) {
            if (preg_match('/^--playlist=(\d+)$/', $arg, $m)) { $opts['playlist'] = $m[1]; }
        }
        if ($con->fetchOne('SELECT COUNT(*) FROM edificio WHERE nombre = ?', [EDIFICIO_SIM]) > 0) {
            fwrite(STDERR, "Ya hay una estructura [SIM]: correr `limpiar` primero.\n");
            exit(1);
        }

        // Contenido realista: la playlist de respaldo de una pantalla real (o la indicada).
        $playlist = isset($opts['playlist'])
            ? $em->find(Playlist::class, (int) $opts['playlist'])
            : ($em->getRepository(Pantalla::class)->createQueryBuilder('p')
                ->where('p.playlistFallback IS NOT NULL')->setMaxResults(1)->getQuery()->getOneOrNullResult()?->getPlaylistFallback()
              ?? $em->getRepository(Playlist::class)->findOneBy([]));
        if ($playlist === null) {
            fwrite(STDERR, "No hay ninguna playlist para usar como contenido de las pantallas [SIM].\n");
            exit(1);
        }

        $salt = $_SERVER['SPUI_MQTT_SALT'] ?? $_ENV['SPUI_MQTT_SALT'] ?? getenv('SPUI_MQTT_SALT') ?: '';
        if ($salt === '') {
            fwrite(STDERR, "Falta SPUI_MQTT_SALT en el entorno del CMS.\n");
            exit(1);
        }

        $edificio = (new Edificio())->setNombre(EDIFICIO_SIM)->setDescripcion('Datos temporales del simulador de reproductores. Se borran solos al terminar la corrida.')->setActivo(true);
        $ubicacion = (new Ubicacion())->setEdificio($edificio)->setSector('[SIM] Simulación')->setActivo(true);
        $em->persist($edificio);
        $em->persist($ubicacion);

        $creados = [];
        for ($i = 1; $i <= $n; $i++) {
            $host   = sprintf('sim-%03d', $i);
            $rawKey = bin2hex(random_bytes(32));
            $rep = (new Reproductor())->setHostname($host)->setVersionFirmware('simulador')->setApiKeyHash(hash('sha256', $rawKey))->setIntervaloSyncMin(1);
            $pan = (new Pantalla())->setNombre('[SIM] Pantalla ' . $i)->setUbicacion($ubicacion)->setReproductor($rep)
                ->setPlaylistFallback($playlist)->setResolucionAncho(1920)->setResolucionAlto(1080)->setActiva(true);
            $em->persist($rep);
            $em->persist($pan);
            $creados[] = [$rep, $pan, $rawKey];
        }
        $em->flush();

        $mqtt = mqttSecurity();
        $estado = ['creado_en' => date('c'), 'playlist' => ['id' => $playlist->getId(), 'nombre' => $playlist->getNombre(), 'items' => $playlist->getItems()->count()], 'reproductores' => []];
        $mqttFallidos = 0;
        foreach ($creados as [$rep, $pan, $rawKey]) {
            $ok = $mqtt->sincronizar($rep, $rawKey);
            $mqttFallidos += $ok ? 0 : 1;
            $estado['reproductores'][] = [
                'id'          => $rep->getId(),
                'hostname'    => $rep->getHostname(),
                'pantalla_id' => $pan->getId(),
                'api_key'     => $rawKey,
                'mqtt_user'   => $rep->mqttUsuario(),
                'mqtt_pass'   => Reproductor::mqttPassword($rawKey, $salt),
            ];
        }
        @mkdir(dirname(ESTADO), 0700, true);
        file_put_contents(ESTADO, json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        salida(['creados' => $n, 'credenciales_mqtt_fallidas' => $mqttFallidos, 'playlist' => $estado['playlist'], 'estado' => realpath(ESTADO)]);
        exit($mqttFallidos ? 1 : 0);

    case 'alerta':
        $pantallas = $em->getRepository(Pantalla::class)->createQueryBuilder('p')->join('p.ubicacion', 'u')->join('u.edificio', 'e')
            ->where('e.nombre = :n')->setParameter('n', EDIFICIO_SIM)->getQuery()->getResult();
        if (!$pantallas) {
            fwrite(STDERR, "No hay pantallas [SIM]: correr `preparar` primero.\n");
            exit(1);
        }
        $usuario = $c->get('doctrine')->getManager('Shared')->getRepository(Shared\Entity\User::class)
            ->findOneBy(['email' => getenv('SPUI_VERIFICACION_EMAIL') ?: 'mateo.rivero@unraf.edu.ar']);
        $alerta = (new AlertaEmergencia())->setTitulo('[SIM] Alerta de prueba de carga')
            ->setMensaje('Prueba automática del simulador. Va dirigida sólo a pantallas [SIM].')
            ->setPrioridad(10)->setCreadoPorId((int) $usuario->getId());
        foreach ($pantallas as $p) {
            $alerta->addPantalla($p);
        }
        $em->persist($alerta);
        $em->flush();
        salida(['alerta_id' => $alerta->getId(), 'pantallas' => count($pantallas)]);
        exit(0);

    case 'integridad':
        $desde = new DateTimeImmutable($argv[2] ?? '-1 hour');
        $ids = $con->fetchFirstColumn("SELECT r.id FROM reproductor r WHERE r.hostname LIKE 'sim-%'");
        if (!$ids) {
            salida(['error' => 'no hay reproductores [SIM]']);
            exit(1);
        }
        $lista = implode(',', array_map('intval', $ids));
        $d = $desde->format('Y-m-d H:i:s');
        $telemetria = $con->fetchAllAssociative("SELECT reproductor_id, COUNT(*) n, MIN(registrado_en) primero, MAX(registrado_en) ultimo FROM telemetria WHERE reproductor_id IN ($lista) AND registrado_en >= ? GROUP BY reproductor_id", [$d]);
        $sinSync = (int) $con->fetchOne("SELECT COUNT(*) FROM reproductor WHERE id IN ($lista) AND (ultimo_sync IS NULL OR ultimo_sync < ?)", [$d]);
        $sinHeartbeat = (int) $con->fetchOne("SELECT COUNT(*) FROM reproductor WHERE id IN ($lista) AND (ultimo_heartbeat IS NULL OR ultimo_heartbeat < ?)", [$d]);
        $huerfanas = (int) $con->fetchOne('SELECT COUNT(*) FROM telemetria t LEFT JOIN reproductor r ON r.id = t.reproductor_id WHERE r.id IS NULL');
        $valoresRaros = (int) $con->fetchOne("SELECT COUNT(*) FROM telemetria WHERE reproductor_id IN ($lista) AND registrado_en >= ? AND (uso_ram_porcentaje < 0 OR uso_ram_porcentaje > 100 OR espacio_disco_libre_mb < 0)", [$d]);
        $conDiagnostico = (int) $con->fetchOne("SELECT COUNT(*) FROM reproductor WHERE id IN ($lista) AND diagnostico IS NOT NULL AND diagnostico <> '[]'");

        // Errores del CMS durante la corrida, en el log de la app.
        $log = SPUI_RAIZ_MONOREPO . '/var/log/spui/dev.log';
        $errores = [];
        if (is_file($log)) {
            $fh = fopen($log, 'r');
            fseek($fh, max(0, filesize($log) - 20 * 1024 * 1024));
            while (($linea = fgets($fh)) !== false) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}T[\d:.]+[^\]]*)\] (\w+)\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.{0,300})/', $linea, $m)
                    && new DateTimeImmutable($m[1]) >= $desde) {
                    $clave = preg_replace('/\d+/', 'N', $m[4]);
                    $errores[$clave] = ($errores[$clave] ?? 0) + 1;
                }
            }
            fclose($fh);
        }
        arsort($errores);
        salida([
            'reproductores' => count($ids),
            'telemetria_por_reproductor' => $telemetria,
            'reproductores_sin_sync_en_la_corrida' => $sinSync,
            'reproductores_sin_heartbeat_en_la_corrida' => $sinHeartbeat,
            'telemetria_huerfana_en_toda_la_base' => $huerfanas,
            'telemetria_con_valores_fuera_de_rango' => $valoresRaros,
            'reproductores_con_diagnostico' => $conDiagnostico,
            'errores_del_cms_en_el_log' => array_slice($errores, 0, 15, true),
        ]);
        exit(0);

    case 'limpiar':
        $reps = $em->getRepository(Reproductor::class)->createQueryBuilder('r')->where("r.hostname LIKE 'sim-%'")->getQuery()->getResult();
        $mqtt = mqttSecurity();
        $revocados = 0;
        foreach ($reps as $r) {
            $revocados += $mqtt->revocar($r) ? 1 : 0;
        }
        $ids = array_map(fn($r) => (int) $r->getId(), $reps);
        $lista = $ids ? implode(',', $ids) : '0';
        $con->beginTransaction();
        try {
            $alertas = $con->fetchFirstColumn("SELECT id FROM alerta_emergencia WHERE titulo LIKE '[SIM]%'");
            $listaAl = $alertas ? implode(',', array_map('intval', $alertas)) : '0';
            $pantallas = $con->fetchFirstColumn("SELECT p.id FROM pantalla p JOIN ubicacion u ON u.id = p.ubicacion_id JOIN edificio e ON e.id = u.edificio_id WHERE e.nombre = ?", [EDIFICIO_SIM]);
            $listaPa = $pantallas ? implode(',', array_map('intval', $pantallas)) : '0';
            $borrado = [
                'alerta_pantalla'   => $con->executeStatement("DELETE FROM alerta_pantalla WHERE alerta_emergencia_id IN ($listaAl) OR pantalla_id IN ($listaPa)"),
                'alerta_emergencia' => $con->executeStatement("DELETE FROM alerta_emergencia WHERE id IN ($listaAl)"),
                'telemetria'        => $con->executeStatement("DELETE FROM telemetria WHERE reproductor_id IN ($lista)"),
                'telemetria_hora'   => $con->executeStatement("DELETE FROM telemetria_hora WHERE reproductor_id IN ($lista)"),
                'programacion_energetica' => $con->executeStatement("DELETE FROM programacion_energetica WHERE pantalla_id IN ($listaPa)"),
                'pantalla'          => $con->executeStatement("DELETE FROM pantalla WHERE id IN ($listaPa)"),
                'reproductor'       => $con->executeStatement("DELETE FROM reproductor WHERE id IN ($lista)"),
                'ubicacion'         => $con->executeStatement('DELETE u FROM ubicacion u JOIN edificio e ON e.id = u.edificio_id WHERE e.nombre = ?', [EDIFICIO_SIM]),
                'edificio'          => $con->executeStatement('DELETE FROM edificio WHERE nombre = ?', [EDIFICIO_SIM]),
            ];
            $con->commit();
        } catch (Throwable $e) {
            $con->rollBack();
            fwrite(STDERR, 'No se pudo limpiar: ' . $e->getMessage() . "\n");
            exit(1);
        }
        @unlink(ESTADO);
        salida(['credenciales_mqtt_revocadas' => $revocados, 'de' => count($reps), 'filas_borradas' => $borrado]);
        exit($revocados === count($reps) ? 0 : 1);

    default:
        fwrite(STDERR, "Uso: php tools/simulador/sim.php preparar <N> [--playlist=<id>] | alerta | integridad <desde> | limpiar\n");
        exit(2);
}
