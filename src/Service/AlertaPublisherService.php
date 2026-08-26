<?php

declare(strict_types=1);

namespace SPUI\Service;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use SPUI\Entity\AlertaEmergencia;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publica eventos de alerta de emergencia hacia:
 * - Reproductores Pi vía MQTT (broker Mosquitto): reciben el override inmediatamente
 * - Dashboard web vía Mercure SSE: el panel admin se actualiza en tiempo real
 *
 * Ambos canales fallan de forma silenciosa si el broker/hub no está disponible
 * (registra un warning en lugar de romper la activación de la alerta).
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

    private const MERCURE_TOPIC = 'spui-alertas';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
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
            'sonido_url'     => ($baseUrl !== null && $alerta->getSonidoArchivo() !== null)
                ? $baseUrl . '/api/spui/media/' . rawurlencode($alerta->getSonidoArchivo())
                : null,
            'sonido_hash'    => $alerta->getSonidoHashArchivo(),
            'contenido_tipo' => $contenido?->getTipo()->value,
            'contenido_url'  => ($baseUrl !== null && $contenido?->getRutaArchivo() !== null)
                ? $baseUrl . '/api/spui/media/' . rawurlencode(basename($contenido->getRutaArchivo()))
                : null,
            'contenido_hash' => $contenido?->getHashArchivo(),
        ];

        // retain=true → los reproductores que se reconecten después también reciben la alerta
        foreach ($this->topicsDestino($alerta) as $topic) {
            $this->publicarMqtt($topic, $payload, retain: true);
        }

        // El dashboard del CMS siempre escucha el mismo topic, sea global o dirigida.
        $this->publicarMercure(self::MERCURE_TOPIC, $payload);
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

        foreach ($this->topicsDestino($alerta) as $topic) {
            // Aviso a los reproductores que están conectados ahora.
            $this->publicarMqtt($topic, $payload, retain: false);

            // Y además limpiar el mensaje retenido, o el broker le seguiría
            // entregando la alerta ya desactivada a cualquier Pi que se reconecte.
            // El protocolo MQTT exige payload de cero bytes CON retain=true: un
            // publish con retain=false no borra nada (era el bug anterior).
            $this->limpiarRetenido($topic);
        }

        $this->publicarMercure(self::MERCURE_TOPIC, $payload);
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

    private function publicarMqtt(string $topic, array $payload, bool $retain): void
    {
        try {
            $client = new MqttClient($this->mqttHost, $this->mqttPort, 'spui-cms-publisher');
            $client->connect($this->conexion(), useCleanSession: true);
            $client->publish($topic, json_encode($payload), MqttClient::QOS_AT_MOST_ONCE, $retain);
            $client->disconnect();

            $this->logger->info('MQTT alerta publicada.', ['topic' => $topic, 'accion' => $payload['accion']]);
        } catch (\Throwable $e) {
            // No bloquear la respuesta HTTP si el broker no está disponible
            $this->logger->warning('MQTT publish falló (¿Mosquitto corriendo en {host}:{port}?): {msg}', [
                'host'  => $this->mqttHost,
                'port'  => $this->mqttPort,
                'msg'   => $e->getMessage(),
                'topic' => $topic,
            ]);
        }
    }

    /**
     * Borra el mensaje retenido de un topic.
     *
     * Publicar payload vacío con retain=true es la forma que define el
     * protocolo MQTT para que el broker olvide lo retenido; los suscriptores
     * que se conecten después ya no reciben nada de este topic.
     */
    private function limpiarRetenido(string $topic): void
    {
        try {
            $client = new MqttClient($this->mqttHost, $this->mqttPort, 'spui-cms-publisher-clear');
            $client->connect($this->conexion(), useCleanSession: true);
            $client->publish($topic, '', MqttClient::QOS_AT_MOST_ONCE, true);
            $client->disconnect();

            $this->logger->info('MQTT mensaje retenido limpiado.', ['topic' => $topic]);
        } catch (\Throwable $e) {
            $this->logger->warning('MQTT limpieza de retenido falló: {msg}', [
                'msg'   => $e->getMessage(),
                'topic' => $topic,
            ]);
        }
    }

    private function publicarMercure(string $topic, array $payload): void
    {
        try {
            $update = new Update($topic, json_encode($payload));
            $this->hub->publish($update);

            $this->logger->info('Mercure alerta publicada.', ['topic' => $topic, 'accion' => $payload['accion']]);
        } catch (\Throwable $e) {
            // No bloquear la respuesta HTTP si el hub Mercure no está disponible
            $this->logger->warning('Mercure publish falló (¿hub corriendo?): {msg}', [
                'msg'   => $e->getMessage(),
                'topic' => $topic,
            ]);
        }
    }
}
