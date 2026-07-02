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
 * - Nodos Pi vía MQTT (broker Mosquitto): reciben el override inmediatamente
 * - Dashboard web vía Mercure SSE: el panel admin se actualiza en tiempo real
 *
 * Ambos canales fallan de forma silenciosa si el broker/hub no está disponible
 * (registra un warning en lugar de romper la activación de la alerta).
 */
final class AlertaPublisherService
{
    private const MQTT_TOPIC_ALERTA  = 'spui/alertas/emergencia';
    private const MERCURE_TOPIC      = 'spui-alertas';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:MQTT_HOST)%')]
        private readonly string $mqttHost,
        #[Autowire('%env(int:MQTT_PORT)%')]
        private readonly int $mqttPort,
    ) {}

    /**
     * Llama esto al activar una alerta.
     * Los nodos Pi recibirán el mensaje MQTT y overridearán su contenido actual.
     */
    public function publicarActivacion(AlertaEmergencia $alerta): void
    {
        $payload = [
            'accion'    => 'activada',
            'id'        => $alerta->getId(),
            'titulo'    => $alerta->getTitulo(),
            'mensaje'   => $alerta->getMensaje(),
            'prioridad' => $alerta->getPrioridad(),
            'expira_en' => $alerta->getExpiraEn()?->format('c'),
        ];

        // retain=true → los nodos que se reconecten después también reciben la alerta
        $this->publicarMqtt(self::MQTT_TOPIC_ALERTA, $payload, retain: true);
        $this->publicarMercure(self::MERCURE_TOPIC, $payload);
    }

    /**
     * Llama esto al desactivar una alerta.
     * Los nodos Pi vuelven a su programación normal.
     */
    public function publicarDesactivacion(AlertaEmergencia $alerta): void
    {
        $payload = [
            'accion'    => 'desactivada',
            'id'        => $alerta->getId(),
        ];

        // retain=false + payload vacío limpia el mensaje retenido en el topic
        $this->publicarMqtt(self::MQTT_TOPIC_ALERTA, $payload, retain: false);
        $this->publicarMercure(self::MERCURE_TOPIC, $payload);
    }

    // -------------------------------------------------------------------------

    private function publicarMqtt(string $topic, array $payload, bool $retain): void
    {
        try {
            $settings = (new ConnectionSettings())
                ->setConnectTimeout(3)
                ->setSocketTimeout(3);

            $client = new MqttClient($this->mqttHost, $this->mqttPort, 'spui-cms-publisher');
            $client->connect($settings, cleanSession: true);
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
