<?php

declare(strict_types=1);

namespace SPUI\Service;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use SPUI\Entity\Reproductor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Empuja comandos a reproductores puntuales por MQTT — hoy sólo "sincronizá
 * ahora" (tarea 1.5), para que un cambio de programación/playlist/contenido/
 * pantalla llegue a la pantalla en segundos en vez de esperar el sync
 * periódico (hasta 300s).
 *
 * Patrón "señal MQTT + pull HTTP": acá NO viaja el dato nuevo, sólo el aviso
 * de que hay algo para buscar. La Pi sigue pidiendo los datos por HTTP con su
 * propia autenticación y con confirmación de entrega — MQTT sólo evita tener
 * que esperar el próximo ciclo. Si el broker está caído, el sync de 300s
 * sigue siendo la red de seguridad: nada deja de funcionar, sólo deja de ser
 * instantáneo.
 *
 * Mismo patrón de conexión que AlertaPublisherService: conexión corta por
 * publish, nunca rompe la operación del CMS si el broker no está disponible.
 */
final class ComandoPublisherService
{
    private const MQTT_TOPIC_REPRODUCTOR = 'spui/comandos/reproductor/';

    public function __construct(
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
    ) {
    }

    /**
     * Pide a cada reproductor que sincronice ya mismo, sin esperar su
     * próximo ciclo de 300s.
     *
     * @param Reproductor[] $reproductores Los que resolvió AlcanceReproductorService.
     * @param string        $motivo        Sólo para el log — qué disparó el aviso
     *                                     ('programacion', 'playlist', 'contenido', 'pantalla').
     */
    public function pedirSyncAhora(array $reproductores, string $motivo): void
    {
        $payload = ['accion' => 'sync_ahora', 'motivo' => $motivo];

        foreach ($reproductores as $reproductor) {
            $this->publicarMqtt(self::MQTT_TOPIC_REPRODUCTOR . $reproductor->getId(), $payload);
        }
    }

    // -------------------------------------------------------------------------

    private function conexion(): ConnectionSettings
    {
        return $this->mqttConnection
            ->paraCredenciales($this->mqttUser, $this->mqttPass)
            ->setConnectTimeout(3)
            ->setSocketTimeout(3);
    }

    private function publicarMqtt(string $topic, array $payload): void
    {
        try {
            $client = new MqttClient($this->mqttHost, $this->mqttPort, 'spui-cms-comando-' . bin2hex(random_bytes(4)));
            $client->connect($this->conexion(), useCleanSession: true);
            // Sin retain: es un aviso transitorio ("hay algo nuevo, andá a
            // buscarlo"), no un estado a preservar para quien se conecte después
            // — a diferencia de una alerta, acá no tiene sentido que un
            // reproductor que se reconecta mañana reciba un "sincronizá ahora"
            // de un cambio de la semana pasada.
            $client->publish($topic, json_encode($payload), MqttClient::QOS_AT_MOST_ONCE, false);
            $client->disconnect();

            $this->logger->info('MQTT comando publicado.', ['topic' => $topic, 'motivo' => $payload['motivo']]);
        } catch (\Throwable $e) {
            // No bloquear la operación del CMS si el broker no está disponible.
            // El reproductor va a recibir el cambio igual en su próximo sync
            // periódico — sólo se pierde la instantaneidad, no el dato.
            $this->logger->warning('MQTT comando falló (¿Mosquitto corriendo en {host}:{port}?): {msg}', [
                'host'  => $this->mqttHost,
                'port'  => $this->mqttPort,
                'msg'   => $e->getMessage(),
                'topic' => $topic,
            ]);
        }
    }
}
