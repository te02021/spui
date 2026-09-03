<?php

declare(strict_types=1);

namespace SPUI\Service;

use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use SPUI\Entity\CodigoQr;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Avisa por MQTT que se escaneó un código QR.
 *
 * Existe para que el contador de escaneos del CMS se mueva en el momento del
 * escaneo y no cuando alguien recarga la página. La alternativa —que el panel
 * preguntara cada X segundos si cambió algo— se descartó: un escaneo puede no
 * pasar en todo el día o pasar diez veces en un minuto, así que consultar a
 * intervalo fijo gasta ancho de banda casi siempre para no enterarse de nada.
 * Acá el navegador está callado hasta que el broker le empuja el mensaje.
 *
 * A diferencia de las alertas, esto NO lo escucha ningún reproductor: el único
 * suscriptor es el navegador del panel, por el listener WebSocket del broker
 * (ver config/mosquitto/mosquitto.conf).
 *
 * Falla en silencio, con un warning al log: que Mosquitto esté caído no puede
 * romper el escaneo de un QR. Quien escanea llega igual a destino y el número
 * queda bien en la base — lo único que se pierde es que el panel se entere en
 * el momento, y en la próxima apertura del modal lo lee de la base igual.
 */
final class QrEscaneoPublisherService
{
    /**
     * Un topic por código. El navegador se suscribe sólo al del código que
     * está mirando, así abrir el modal de un QR no le hace llegar el ruido de
     * todos los demás.
     */
    private const TOPIC = 'spui/qr/escaneo/';

    /**
     * El topic de un código, en un solo lugar.
     *
     * Lo necesitan los dos extremos: este servicio para publicar y el CMS para
     * decirle al navegador a qué suscribirse. Escrito dos veces, una diferencia
     * de una letra dejaría el contador mudo sin ningún error a la vista.
     */
    public static function topicPara(int $codigoId): string
    {
        return self::TOPIC . $codigoId;
    }

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
    ) {}

    /**
     * Publica el total de escaneos del código, ya incrementado y persistido.
     *
     * Se manda el total y no un "+1" para que el panel no tenga que llevar la
     * cuenta por su lado: si se perdió un mensaje o el modal se abrió recién,
     * el próximo escaneo lo deja igual en el número correcto.
     */
    public function publicar(CodigoQr $qr): void
    {
        $topic = self::topicPara($qr->getId());

        try {
            $client = new MqttClient(
                $this->mqttHost,
                $this->mqttPort,
                // Sufijo aleatorio: MQTT desconecta al cliente anterior cuando
                // se conecta otro con el mismo id, y dos escaneos simultáneos
                // se cortarían entre sí.
                'spui-cms-qr-' . bin2hex(random_bytes(4)),
            );

            // Timeout mucho más corto que el resto de los publishers MQTT del
            // proyecto (que usan 3s): éste es el único que corre dentro de
            // CodigoQrController::redirigir(), el único punto donde el que
            // espera es quien escaneó el cartel, no un operador ya interactuando
            // con el panel. Medido: con el broker inalcanzable (red colgada, no
            // rechazo activo), 3s de timeout se sentían como 3s de más antes del
            // redirect. Con esto el peor caso queda en un segundo — perceptible
            // pero ya no un delay incómodo — y el caso normal (broker arriba)
            // no cambia en nada.
            $client->connect(
                $this->mqttConnection
                    ->paraCredenciales($this->mqttUser, $this->mqttPass)
                    ->setConnectTimeout(1)
                    ->setSocketTimeout(1),
                useCleanSession: true,
            );

            // retain=false a propósito: un retenido le entregaría a cualquier
            // panel que abra el modal un número viejo del broker, que puede
            // estar atrasado respecto de la base. El valor de arranque siempre
            // sale de la base, al renderizar el modal.
            $client->publish(
                $topic,
                json_encode(['id' => $qr->getId(), 'usos' => $qr->getUsosCount()]),
                MqttClient::QOS_AT_MOST_ONCE,
                false,
            );
            $client->disconnect();

            $this->logger->info('MQTT escaneo de QR publicado.', ['topic' => $topic]);
        } catch (\Throwable $e) {
            $this->logger->warning('MQTT escaneo de QR falló (¿Mosquitto corriendo en {host}:{port}?): {msg}', [
                'host'  => $this->mqttHost,
                'port'  => $this->mqttPort,
                'topic' => $topic,
                'msg'   => $e->getMessage(),
            ]);
        }
    }
}
