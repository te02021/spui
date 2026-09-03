<?php

declare(strict_types=1);

namespace SPUI\Service;

use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use SPUI\Entity\Reproductor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

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

    /** Rol del propio CMS (creado a mano — ver config/mosquitto/README.md). */
    public const ROL_CMS = 'spui-cms-rol';

    /**
     * Usuario y rol del navegador: el panel del CMS suscrito a spui/qr/# para
     * ver los escaneos en vivo. Es el único cliente MQTT cuyas credenciales
     * viajan al navegador, así que su rol es de SÓLO LECTURA y sólo sobre
     * spui/qr/# — quien las tome de la página no puede publicar nada ni leer
     * alertas, comandos ni telemetría.
     */
    public const USUARIO_WEB = 'spui-cms-web';
    public const ROL_WEB     = 'spui-cms-web';

    /**
     * Puerto del listener WebSocket del broker (ver config/mosquitto/
     * mosquitto.conf). Constante y no variable de entorno a propósito: es un
     * detalle del broker que el CMS ya conoce por el archivo de config que
     * versiona él mismo, y sumar otra variable sería configurar dos veces lo
     * mismo con riesgo de que queden distintas.
     */
    public const PUERTO_WEBSOCKET = 9001;

    /**
     * Ruta por la que Apache reexpone el listener WebSocket cuando el CMS se
     * sirve por HTTPS (ver config/mosquitto/README.md, sección de producción).
     *
     * Lleva el nombre de la app porque el vhost es compartido con el resto de
     * la intranet: la ruta cuelga del prefijo de esta instalación, no de la
     * raíz del dominio.
     */
    public const RUTA_WEBSOCKET = '/spui-mqtt';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MqttConnectionFactory $mqttConnection,
        private readonly CacheInterface $cache,
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

    // ── Cliente del navegador (contador de escaneos en vivo) ─────────────────

    /**
     * Contraseña del usuario web, derivada del salt que ya existe.
     *
     * Mismo criterio que la del reproductor (Reproductor::mqttPassword): no es
     * un secreto independiente. Así no hay que sumar otra variable de entorno
     * ni un paso manual de instalación — el CMS deriva la contraseña, da de
     * alta el cliente en el broker y se la pasa al navegador, todo solo.
     *
     * Que sea derivable con el salt no la debilita en la práctica: esta
     * contraseña VIAJA AL NAVEGADOR y se lee en el HTML de la página. Lo que
     * la vuelve inofensiva no es que sea secreta, es su ACL de sólo lectura
     * sobre spui/qr/#.
     */
    public function passwordWeb(): string
    {
        return hash('sha256', self::USUARIO_WEB . $this->salt);
    }

    /**
     * URL del broker tal como la tiene que ver el navegador.
     *
     * Se usa el host por el que el navegador llegó al CMS, NO MQTT_HOST. Es el
     * mismo criterio que QrGeneratorService::urlRedirect(): el que abre el
     * panel puede estar en cualquier máquina, y lo único que sabemos con
     * certeza que él alcanza es la dirección por la que acaba de entrar.
     *
     * MQTT_HOST no sirve acá aunque parezca el dato correcto: hoy vale
     * 'spui-broker.unraf.local', un nombre que existe en el archivo hosts de
     * la máquina del CMS (apuntando a 127.0.0.1) y que ninguna otra computadora
     * resuelve. Pasárselo al navegador de un operador remoto lo dejaría
     * intentando conectarse a un host inexistente, sin ningún error visible más
     * que un contador que no se mueve.
     *
     * Esto vale porque el broker corre en la misma máquina que el CMS, en
     * desarrollo y en la VM. Si algún día se separan, este método es el único
     * lugar a cambiar.
     *
     * Hay dos formas de la URL según cómo se sirva el CMS, y no es una
     * preferencia: un navegador BLOQUEA ws:// dentro de una página https://
     * por contenido mixto, en silencio, dejando sólo un contador que no se
     * mueve.
     *
     * - CMS por http (desarrollo): ws:// directo al puerto 9001 del broker.
     * - CMS por https (producción): wss:// por el puerto del propio sitio,
     *   contra la ruta que Apache reexpone con mod_proxy_wstunnel hacia
     *   ws://127.0.0.1:9001. Así viaja con el certificado del sitio, sin abrir
     *   ningún puerto más ni emitir un certificado propio para el broker —
     *   que además, siendo autofirmado, cada operador tendría que confiar a
     *   mano en su navegador.
     *
     * Se usa getBasePath() y no la raíz del dominio porque en producción esta
     * app cuelga de un prefijo dentro de la intranet, compartiendo vhost con
     * las demás. getBasePath() ya trae ese prefijo sin el front controller, y
     * en desarrollo (DocumentRoot apuntando a public/) vale ''.
     */
    public function urlWebsocket(Request $request): string
    {
        if ($request->isSecure()) {
            return 'wss://' . $request->getHttpHost() . $request->getBasePath() . self::RUTA_WEBSOCKET;
        }

        return 'ws://' . $request->getHost() . ':' . self::PUERTO_WEBSOCKET;
    }

    /**
     * Da de alta el rol y el cliente del navegador, una sola vez.
     *
     * Se llama al abrir el modal de un contenido QR, no en la instalación: el
     * pedido era que no hubiera ningún paso manual de configuración. El
     * resultado se cachea para no abrir una conexión al broker en cada
     * apertura del modal, pero con TTL — si el broker se reinstala (ya pasó
     * dos veces, ver el encabezado de mosquitto.conf), el alta se rehace sola
     * dentro de la hora en vez de quedar rota para siempre.
     *
     * También le agrega al rol del CMS el permiso de publicar en spui/qr/#:
     * sin eso el broker acepta la conexión del publicador y descarta el
     * mensaje del escaneo en silencio, que es de los fallos más difíciles de
     * ver acá.
     */
    public function asegurarClienteWeb(): bool
    {
        return $this->cache->get('spui.mqtt.cliente_web', function (ItemInterface $item): bool {
            $comandos = [
                ['command' => 'createRole', 'rolename' => self::ROL_WEB],
                [
                    'command'  => 'addRoleACL',
                    'rolename' => self::ROL_WEB,
                    'acltype'  => 'subscribePattern',
                    'topic'    => 'spui/qr/#',
                    'priority' => -1,
                    'allow'    => true,
                ],
                // Suscribirse no alcanza: sin publishClientReceive el broker
                // acepta la suscripción y después no le entrega ningún mensaje.
                [
                    'command'  => 'addRoleACL',
                    'rolename' => self::ROL_WEB,
                    'acltype'  => 'publishClientReceive',
                    'topic'    => 'spui/qr/#',
                    'priority' => -1,
                    'allow'    => true,
                ],
                [
                    'command'  => 'createClient',
                    'username' => self::USUARIO_WEB,
                    'password' => $this->passwordWeb(),
                ],
                [
                    'command'  => 'setClientPassword',
                    'username' => self::USUARIO_WEB,
                    'password' => $this->passwordWeb(),
                ],
                [
                    'command'  => 'addClientRole',
                    'username' => self::USUARIO_WEB,
                    'rolename' => self::ROL_WEB,
                ],
                // El permiso que le falta al publicador del propio CMS.
                [
                    'command'  => 'addRoleACL',
                    'rolename' => self::ROL_CMS,
                    'acltype'  => 'publishClientSend',
                    'topic'    => 'spui/qr/#',
                    'priority' => -1,
                    'allow'    => true,
                ],
            ];

            $ok = $this->enviar($comandos, 'alta del cliente web', self::USUARIO_WEB);

            // Broker caído: reintentar enseguida, no dejar el contador muerto
            // una hora entera por una caída de un minuto.
            $item->expiresAfter($ok ? 3600 : 60);

            return $ok;
        });
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
        return $this->mqttConnection
            ->paraCredenciales($this->adminUser, $this->adminPass)
            ->setConnectTimeout(3)
            ->setSocketTimeout(3);
    }
}
