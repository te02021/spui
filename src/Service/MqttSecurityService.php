<?php

declare(strict_types=1);

namespace SPUI\Service;

use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use SPUI\Entity\Reproductor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Alta y baja de credenciales MQTT de los reproductores en el broker.
 *
 * Mosquitto 2.x expone el plugin Dynamic Security, que administra usuarios y
 * permisos publicando en el topic $CONTROL/dynamic-security/v1 en vez de
 * escribir archivos de texto. Eso es lo que permite que el CMS dé de alta un
 * reproductor sin tocar el disco del broker ni reiniciarlo.
 *
 * La alternativa (password_file + acl_file) exigiría que PHP ejecutara
 * mosquitto_passwd.exe dentro del directorio de instalación y reiniciara el
 * servicio de Windows. Ese directorio requiere permisos de administrador —
 * verificado en el entorno de desarrollo, donde una shell normal no puede ni
 * escribir un backup ahí — así que en la VM fallaría.
 *
 * Todas las operaciones degradan a un warning en el log: que el broker esté
 * caído no puede impedir dar de alta un reproductor en el CMS. La credencial
 * se sincroniza después con sincronizar().
 */
final class MqttSecurityService
{
    /** Topic de control del plugin. Sólo el usuario admin puede publicar acá. */
    private const TOPIC_CONTROL = '$CONTROL/dynamic-security/v1';

    /**
     * Rol que agrupa los permisos de un reproductor.
     *
     * Se crea en la tarea 1.0.b; hasta entonces los clientes se crean sin rol
     * y el broker les aplica el default. Asignarlo ahora es inocuo: si el rol
     * todavía no existe, el broker responde con error y se loguea, sin romper
     * el alta.
     */
    public const ROL_REPRODUCTOR = 'spui-reproductor';

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:MQTT_HOST)%')]
        private readonly string $mqttHost,
        #[Autowire('%env(int:MQTT_PORT)%')]
        private readonly int $mqttPort,
        #[Autowire('%env(string:MQTT_ADMIN_USER)%')]
        private readonly string $adminUser,
        #[Autowire('%env(string:MQTT_ADMIN_PASS)%')]
        private readonly string $adminPass,
        #[Autowire('%env(string:SPUI_MQTT_SALT)%')]
        private readonly string $salt,
    ) {}

    /**
     * Da de alta (o actualiza) la credencial MQTT de un reproductor.
     *
     * Se llama al crear el reproductor y al regenerar su API key, porque la
     * contraseña MQTT se deriva de ella: si cambia la API key y no se
     * sincroniza acá, el equipo autentica bien por HTTP pero el broker lo
     * rechaza, y las alertas dejan de llegar sin ningún error visible.
     *
     * Es idempotente: si el cliente ya existe se le actualiza la contraseña en
     * lugar de fallar.
     *
     * @param string $rawKey La API key en claro (sólo disponible al generarla).
     * @return bool true si el broker confirmó la operación.
     */
    public function sincronizar(Reproductor $reproductor, string $rawKey): bool
    {
        $usuario  = $reproductor->mqttUsuario();
        $password = Reproductor::mqttPassword($rawKey, $this->salt);

        // createClient falla si ya existe, así que se intenta primero el alta y
        // se cae a actualizar la contraseña. El orden importa: es el camino que
        // funciona tanto para un reproductor nuevo como para uno al que se le
        // regeneró la clave.
        $comandos = [
            [
                'command'  => 'createClient',
                'username' => $usuario,
                'password' => $password,
            ],
            [
                'command'  => 'setClientPassword',
                'username' => $usuario,
                'password' => $password,
            ],
            [
                'command'  => 'addClientRole',
                'username' => $usuario,
                'rolename' => self::ROL_REPRODUCTOR,
            ],
        ];

        return $this->enviar($comandos, 'alta/actualización', $usuario);
    }

    /**
     * Revoca la credencial MQTT de un reproductor.
     *
     * Se llama al eliminar el reproductor del CMS. Si no se hiciera, la
     * credencial quedaría válida en el broker indefinidamente y un equipo dado
     * de baja podría seguir conectándose.
     */
    public function revocar(Reproductor $reproductor): bool
    {
        return $this->enviar(
            [['command' => 'deleteClient', 'username' => $reproductor->mqttUsuario()]],
            'revocación',
            $reproductor->mqttUsuario(),
        );
    }

    /**
     * Deshabilita la credencial sin borrarla.
     *
     * Útil para sacar de circulación un equipo sospechoso conservando su
     * configuración: enableClient lo revierte sin tener que regenerar la clave.
     */
    public function deshabilitar(Reproductor $reproductor): bool
    {
        return $this->enviar(
            [['command' => 'disableClient', 'username' => $reproductor->mqttUsuario()]],
            'deshabilitación',
            $reproductor->mqttUsuario(),
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Publica una tanda de comandos en el topic de control.
     *
     * El plugin acepta varios comandos en un solo mensaje y los procesa en
     * orden, así que alta + contraseña + rol viajan juntos.
     *
     * Se publica con QoS 1: a diferencia de una alerta, que si se pierde se
     * vuelve a intentar en el próximo sync, perder un comando de alta dejaría
     * al reproductor sin poder conectarse nunca y sin ninguna señal de por qué.
     *
     * @param list<array<string,mixed>> $comandos
     */
    private function enviar(array $comandos, string $operacion, string $usuario): bool
    {
        try {
            $client = new MqttClient(
                $this->mqttHost,
                $this->mqttPort,
                // El client_id lleva un sufijo aleatorio porque MQTT desconecta
                // al cliente anterior cuando se conecta otro con el mismo id.
                // Dos altas simultáneas desde el CMS se cortarían entre sí.
                'spui-cms-dynsec-' . bin2hex(random_bytes(4)),
            );

            $client->connect($this->conexionAdmin(), useCleanSession: true);
            $client->publish(
                self::TOPIC_CONTROL,
                json_encode(['commands' => $comandos], JSON_THROW_ON_ERROR),
                MqttClient::QOS_AT_LEAST_ONCE,
            );
            $client->disconnect();

            $this->logger->info('MQTT credencial: {op} de {usuario} enviada al broker.', [
                'op'      => $operacion,
                'usuario' => $usuario,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Nunca romper la operación del CMS por esto. El reproductor queda
            // dado de alta y funcionando por HTTP; lo único que falta es su
            // credencial MQTT, que se resincroniza volviendo a guardar.
            $this->logger->warning(
                'MQTT credencial: falló la {op} de {usuario} (¿broker en {host}:{port}?): {msg}',
                [
                    'op'      => $operacion,
                    'usuario' => $usuario,
                    'host'    => $this->mqttHost,
                    'port'    => $this->mqttPort,
                    'msg'     => $e->getMessage(),
                ],
            );

            return false;
        }
    }

    private function conexionAdmin(): \PhpMqtt\Client\ConnectionSettings
    {
        return (new \PhpMqtt\Client\ConnectionSettings())
            ->setUsername($this->adminUser)
            ->setPassword($this->adminPass)
            ->setConnectTimeout(3)
            ->setSocketTimeout(3);
    }
}
