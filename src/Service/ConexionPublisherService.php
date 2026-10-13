<?php

declare(strict_types=1);

namespace SPUI\Service;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Avisa al navegador que un reproductor cambió de estado de conexión.
 *
 * Existe para que un botón bloqueado por "reproductor desconectado" se
 * destrabe solo cuando el equipo vuelve (y se trabe cuando se cae) en los
 * listados que no se refrescan por intervalo: Pantallas, Contenidos, Playlists
 * y Programación. El navegador está suscrito por WebSocket y, al recibir el
 * aviso, vuelve a pedir la tabla (ver conexion-vivo.js). Sin ningún timer:
 * mientras nada cambia, la página no manda un solo byte.
 *
 * Lo publica el CMS y no la Pi, a propósito:
 *
 *  - El estado de conexión lo DECIDE el CMS (Reproductor::estadoCalculado(),
 *    por la edad del último heartbeat). El "offline" que la Pi manda por LWT
 *    el CMS lo ignora a propósito, así que escucharlo no reflejaría nada.
 *  - Se publica DESPUÉS de grabar en la base. Si el navegador escuchara el
 *    "online" de la Pi, podría pedir la tabla antes de que el daemon haya
 *    guardado el heartbeat y el botón seguiría bloqueado.
 *
 * Por eso se llama sólo desde los tres puntos donde el estado cambia de
 * verdad: el heartbeat HTTP y el "online" por MQTT (vuelve) y el
 * mantenimiento que marca a los caídos (se va).
 *
 * El mensaje no lleva nada que el navegador tenga que creerle: sólo dispara
 * que vuelva a pedir la tabla, y lo que se muestra lo decide el servidor en
 * ese pedido. Mismo patrón "señal + pull" que ComandoPublisherService.
 */
final class ConexionPublisherService
{
    public const TOPIC_BASE = 'spui/cms/conexion/';

    /** Lo que se le da de lectura al usuario web (MqttSecurityService). */
    public const TOPIC_PATRON = 'spui/cms/conexion/#';

    /** A lo que se suscribe el navegador. */
    public const TOPIC_TODOS = 'spui/cms/conexion/+';

    public const CONECTADO    = 'conectado';
    public const DESCONECTADO = 'desconectado';

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
     * @param list<int> $reproductorIds
     * @param string    $estado         self::CONECTADO o self::DESCONECTADO.
     */
    public function avisar(array $reproductorIds, string $estado): void
    {
        if ($reproductorIds === []) {
            return;
        }

        try {
            $client = new MqttClient($this->mqttHost, $this->mqttPort, 'spui-cms-conexion-' . bin2hex(random_bytes(4)));
            $client->connect($this->conexion(), useCleanSession: true);

            // Una sola conexión para todos: el mantenimiento puede encontrar
            // varios caídos de una vez (un corte de luz en un edificio).
            foreach ($reproductorIds as $id) {
                // Sin retain: es un "algo cambió, volvé a mirar". Una pestaña
                // que se abre después ya trae el estado actual en su HTML.
                $client->publish(
                    self::TOPIC_BASE . $id,
                    json_encode(['reproductor_id' => $id, 'estado' => $estado]),
                    MqttClient::QOS_AT_MOST_ONCE,
                    false,
                );
            }
            $client->disconnect();

            $this->logger->info('MQTT aviso de conexión publicado.', ['ids' => $reproductorIds, 'estado' => $estado]);
        } catch (\Throwable $e) {
            // Nunca romper un heartbeat ni el mantenimiento por esto: sin el
            // aviso, lo único que se pierde es que la tabla se actualice sola
            // — el servidor sigue decidiendo el bloqueo en cada acción.
            $this->logger->warning('MQTT aviso de conexión falló (¿Mosquitto corriendo en {host}:{port}?): {msg}', [
                'host' => $this->mqttHost,
                'port' => $this->mqttPort,
                'msg'  => $e->getMessage(),
                'ids'  => $reproductorIds,
            ]);
        }
    }

    private function conexion(): ConnectionSettings
    {
        return $this->mqttConnection
            ->paraCredenciales($this->mqttUser, $this->mqttPass)
            ->setConnectTimeout(3)
            ->setSocketTimeout(3);
    }
}
