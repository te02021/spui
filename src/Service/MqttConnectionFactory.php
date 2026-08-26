<?php

declare(strict_types=1);

namespace SPUI\Service;

use PhpMqtt\Client\ConnectionSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Arma la configuración base de conexión MQTT (credenciales + TLS si está
 * habilitado), en un solo lugar para los cuatro servicios del CMS que abren
 * su propia conexión al broker (AlertaPublisherService, ComandoPublisherService,
 * MqttSecurityService, MqttSubscribeCommand).
 *
 * Tarea 1.0.e — TLS opt-in por variable de entorno (MQTT_TLS=true), a
 * propósito: el certificado (1.0.c) y el listener 8883 (1.0.d) están
 * escritos pero todavía no desplegados en el broker real. Activar TLS acá
 * antes de que el broker lo sirva dejaría el CMS sin poder conectar. Cuando
 * se despliegue de verdad, alcanza con poner MQTT_TLS=true y MQTT_PORT=8883
 * en el entorno — no hay que tocar código.
 */
final class MqttConnectionFactory
{
    public function __construct(
        #[Autowire('%env(bool:default:spui_mqtt_tls_default:MQTT_TLS)%')]
        private readonly bool $tls,
        #[Autowire('%env(string:default:spui_mqtt_tls_ca_default:MQTT_TLS_CA_FILE)%')]
        private readonly string $tlsCaFile,
    ) {
    }

    /**
     * ConnectionSettings con usuario/password y TLS aplicado si corresponde.
     * El llamador encadena lo que le falte (timeouts, keepalive): son
     * distintos según si la conexión es de un publish corto o de un
     * suscriptor de larga duración.
     */
    public function paraCredenciales(string $usuario, string $password): ConnectionSettings
    {
        return $this->aplicarTls(
            (new ConnectionSettings())
                ->setUsername($usuario)
                ->setPassword($password),
        );
    }

    private function aplicarTls(ConnectionSettings $settings): ConnectionSettings
    {
        if (!$this->tls) {
            return $settings;
        }

        // ConnectionSettings es inmutable: cada setX() devuelve una COPIA
        // nueva, no modifica en el lugar. Hay que reasignar cada llamada o el
        // TLS queda pedido pero nunca aplicado, sin ningún error — se vería
        // como una conexión que "funciona" sin cifrar, el peor tipo de bug acá.
        $settings = $settings->setUseTls(true);

        // Sin CA propia el cliente no podría validar un certificado
        // autofirmado (tarea 1.0.c) y rechazaría la conexión. Con CA
        // configurada, setTlsVerifyPeer(true) por default de la librería
        // sigue exigiendo una cadena válida — correcto, es justo lo que se
        // quiere: cifrado Y verificación, no cifrado a ciegas.
        if ($this->tlsCaFile !== '') {
            $settings = $settings->setTlsCertificateAuthorityFile($this->tlsCaFile);
        }

        return $settings;
    }
}
