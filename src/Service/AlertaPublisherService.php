<?php

declare(strict_types=1);

namespace SPUI\Service;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use SPUI\Entity\AlertaEmergencia;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publica eventos de alerta de emergencia a los reproductores por MQTT (broker
 * Mosquitto): reciben el override inmediatamente.
 *
 * Si el broker no está disponible se registra un warning y la activación sigue:
 * la alerta llega igual por REST en el próximo sync.
 *
 * Ya no publica en Mercure: el hub nunca se completó del lado del navegador (el
 * dashboard se actualiza por polling, ver docs/06 §6) y cada intento fallido le
 * sumaba 2 s fijos a cada Activar y Desactivar (medido en
 * docs/pruebas/2026-10-13_analisis.md §3.2). El bundle sigue en la config
 * compartida de la intranet; SPUI era su único usuario.
 */
final class AlertaPublisherService
{
    /** Alertas globales: lo escuchan todos los reproductores. */
    private const MQTT_TOPIC_ALERTA = 'spui/alertas/emergencia';

    /**
     * Alertas dirigidas: se le agrega el id del reproductor. Cada Pi se
     * suscribe sólo al suyo. Mismo criterio que 'spui/telemetria/{id}'.
     */
    private const MQTT_TOPIC_REPRODUCTOR = 'spui/alertas/reproductor/';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MediaStorageService $media,
        private readonly MqttConnectionFactory $mqttConnection,
        #[Autowire('%env(string:MQTT_HOST)%')]
        private readonly string $mqttHost,
        #[Autowire('%env(int:MQTT_PORT)%')]
        private readonly int $mqttPort,
        #[Autowire('%env(string:MQTT_USER)%')]
        private readonly string $mqttUser,
        #[Autowire('%env(string:MQTT_PASS)%')]
        private readonly string $mqttPass,
    ) {}

    /**
     * Llama esto al activar una alerta.
     * Los reproductores Pi recibirán el mensaje MQTT y overridearán su contenido actual.
     *
     * OJO — este payload NO es el mismo que el de SyncController::serializeAlerta(),
     * pero desde que se agregaron sonido_url/hash y contenido_url/tipo/hash
     * (ver abajo) están MUY cerca: ninguno de los dos manda el archivo en sí,
     * solo strings cortos (URL + hash), así que agregarlos al push inmediato
     * no pesa nada. Si sólo llegaran por REST, un reproductor podría mostrar
     * el texto de la alerta hasta 300 s sin el sonido/imagen correctos —
     * justo lo que se quiere evitar. El propio archivo (la descarga real) el
     * Pi la resuelve igual con su caché+hash habitual, sea por MQTT o por REST
     * de dónde haya sacado la URL.
     *
     * Player.mostrar_alerta() recibe las dos formas, así que sólo puede usar
     * los campos comunes. Si algún día se agrega un campo acá, hay que
     * agregarlo también del lado REST o el cliente se comportará distinto
     * según por dónde le llegó la alerta.
     *
     * $baseUrl: host absoluto (scheme + host) para construir sonido_url y
     * contenido_url. Lo pasa el controller ($request->getSchemeAndHttpHost())
     * porque este servicio no tiene Request propio — se llama también desde
     * el daemon MQTT cuando una alerta se auto-desactiva por vencida
     * (revisarAlertasVencidas), pero esa rama es publicarDesactivacion(), que
     * no necesita ni sonido ni contenido.
     */
    public function publicarActivacion(AlertaEmergencia $alerta, ?string $baseUrl = null): void
    {
        $contenido = $alerta->getContenido();

        $payload = [
            'accion'         => 'activada',
            'id'             => $alerta->getId(),
            'titulo'         => $alerta->getTitulo(),
            'mensaje'        => $alerta->getMensaje(),
            'prioridad'      => $alerta->getPrioridad(),
            'expira_en'      => $alerta->getExpiraEn()?->format('c'),
            'sonido_url'     => ($baseUrl !== null && $this->media->rutaParaUrl($alerta->getSonidoArchivo()) !== null)
                ? $baseUrl . '/api/spui/media/' . $this->media->rutaParaUrl($alerta->getSonidoArchivo())
                : null,
            'sonido_hash'    => $alerta->getSonidoHashArchivo(),
            'contenido_tipo' => $contenido?->getTipo()->value,
            'contenido_url'  => ($baseUrl !== null && $contenido !== null && $this->media->rutaParaUrl($contenido->getRutaArchivo()) !== null)
                ? $baseUrl . '/api/spui/media/' . $this->media->rutaParaUrl($contenido->getRutaArchivo())
                : null,
            'contenido_hash' => $contenido?->getHashArchivo(),
        ];

        // retain=true → los reproductores que se reconecten después también reciben la alerta
        $json = json_encode($payload);
        $this->publicarTanda(array_map(fn(string $t) => [$t, $json, true], $this->topicsDestino($alerta)), 'activada');
    }

    /**
     * Llama esto al desactivar una alerta.
     * Los reproductores Pi vuelven a su programación normal.
     */
    public function publicarDesactivacion(AlertaEmergencia $alerta): void
    {
        $payload = [
            'accion'    => 'desactivada',
            'id'        => $alerta->getId(),
        ];

        $json = json_encode($payload);
        $mensajes = [];
        foreach ($this->topicsDestino($alerta) as $topic) {
            // Aviso a los reproductores que están conectados ahora…
            $mensajes[] = [$topic, $json, false];
            // …y limpiar el mensaje retenido, o el broker le seguiría entregando
            // la alerta ya desactivada a cualquier Pi que se reconecte. El
            // protocolo exige payload de cero bytes CON retain=true: un publish
            // con retain=false no borra nada (era un bug anterior).
            $mensajes[] = [$topic, '', true];
        }
        $this->publicarTanda($mensajes, 'desactivada');
    }

    // -------------------------------------------------------------------------

    /**
     * Topics MQTT a los que hay que publicar esta alerta.
     *
     * Alerta global (sin pantallas elegidas) → el topic de siempre, que todos
     * los reproductores escuchan.
     *
     * Alerta dirigida → un topic por reproductor. Se rutea por reproductor y no
     * por pantalla porque el suscriptor MQTT es el Pi, y un mismo Pi puede
     * manejar varias pantallas; se deduplica para no publicar dos veces al
     * mismo si le tocan dos pantallas de la misma alerta.
     *
     * Una pantalla sin reproductor asignado no genera topic: no hay a quién
     * avisarle. Igual va a recibir la alerta por REST en el próximo sync.
     *
     * @return list<string>
     */
    private function topicsDestino(AlertaEmergencia $alerta): array
    {
        if ($alerta->esGlobal()) {
            return [self::MQTT_TOPIC_ALERTA];
        }

        $topics = [];
        foreach ($alerta->getPantallas() as $pantalla) {
            $reproductor = $pantalla->getReproductor();
            if ($reproductor === null) {
                continue;
            }
            $topics[$reproductor->getId()] = self::MQTT_TOPIC_REPRODUCTOR . $reproductor->getId();
        }

        return array_values($topics);
    }

    /**
     * Parámetros de conexión al broker, en un solo lugar.
     *
     * Las credenciales y el TLS (tarea 1.0.e) salen de MqttConnectionFactory,
     * compartido con los otros tres servicios que hablan con el broker —
     * evita que activar TLS algún día signifique tocar cuatro archivos y
     * olvidarse uno, que es justo lo que este comentario advertía antes de
     * que existiera el factory.
     */
    private function conexion(): ConnectionSettings
    {
        return $this->mqttConnection
            ->paraCredenciales($this->mqttUser, $this->mqttPass)
            ->setConnectTimeout(3)
            ->setSocketTimeout(3);
    }

    /**
     * Publica una tanda de mensajes por UNA sola conexión al broker.
     *
     * Antes se abría, autenticaba con TLS y cerraba una conexión por cada topic:
     * una alerta dirigida a 100 pantallas eran 100 conexiones seguidas, ~30 ms
     * cada una, y el reproductor número 100 recibía la alerta ~3 s después que el
     * primero (medido con tools/simulador, docs/pruebas/2026-10-13_analisis.md
     * §3.1). Con una conexión la tanda sale en milisegundos y el tiempo deja de
     * depender de la cantidad de pantallas.
     *
     * Si la conexión se corta a mitad de la tanda se reconecta una vez y sigue;
     * lo que no se pudo publicar se registra. Nunca lanza: el CMS sigue y el
     * reproductor recibe la alerta igual en su próximo sync.
     *
     * @param list<array{0: string, 1: string, 2: bool}> $mensajes [topic, payload, retain]
     */
    private function publicarTanda(array $mensajes, string $accion): void
    {
        if ($mensajes === []) {
            return;
        }
        $client = null;
        $fallidos = [];
        $error = null;
        $intentosDeConexion = 0;
        $conectoAlgunaVez = false;
        foreach ($mensajes as $i => [$topic, $payload, $retain]) {
            try {
                if ($client === null) {
                    $intentosDeConexion++;
                    $client = $this->conectar();
                    $conectoAlgunaVez = true;
                }
                $client->publish($topic, $payload, MqttClient::QOS_AT_MOST_ONCE, $retain);
            } catch (\Throwable $e) {
                $client = null;
                $error = $e->getMessage();
                // Se reconecta una sola vez por tanda, y sólo si la conexión se
                // cortó a mitad de camino: si el broker ni siquiera atendió, insistir
                // (hasta 3 s por intento) sólo demoraría la respuesta al operador.
                if (!$conectoAlgunaVez || $intentosDeConexion >= 2) {
                    foreach (array_slice($mensajes, $i) as [$t]) {
                        $fallidos[] = $t;
                    }
                    break;
                }
                try {
                    $intentosDeConexion++;
                    $client = $this->conectar();
                    $client->publish($topic, $payload, MqttClient::QOS_AT_MOST_ONCE, $retain);
                } catch (\Throwable $e2) {
                    $client = null;
                    $error = $e2->getMessage();
                    foreach (array_slice($mensajes, $i) as [$t]) {
                        $fallidos[] = $t;
                    }
                    break;
                }
            }
        }
        try {
            $client?->disconnect();
        } catch (\Throwable) {
            // la tanda ya salió; cerrar mal la conexión no cambia nada
        }

        if ($fallidos === []) {
            $this->logger->info('MQTT alerta publicada.', ['accion' => $accion, 'mensajes' => count($mensajes)]);
            return;
        }
        // No bloquear la respuesta HTTP si el broker no está disponible.
        $this->logger->warning('MQTT: {n} de {total} mensajes de alerta sin publicar (¿Mosquitto corriendo en {host}:{port}?): {msg}', [
            'n'      => count($fallidos),
            'total'  => count($mensajes),
            'host'   => $this->mqttHost,
            'port'   => $this->mqttPort,
            'msg'    => $error ?? '',
            'topics' => array_slice($fallidos, 0, 20),
            'accion' => $accion,
        ]);
    }

    private function conectar(): MqttClient
    {
        // Id con sufijo al azar: dos activaciones simultáneas (dos operadores, o
        // el daemon desactivando una vencida) no se tiran la conexión entre sí,
        // que es lo que hace el broker con dos clientes del mismo id.
        $client = new MqttClient($this->mqttHost, $this->mqttPort, 'spui-cms-publisher-' . bin2hex(random_bytes(4)));
        $client->connect($this->conexion(), useCleanSession: true);
        return $client;
    }
}
