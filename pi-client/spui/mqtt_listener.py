"""
Listener MQTT para alertas de emergencia en tiempo real.

Suscribe al topic 'spui/alertas/emergencia' (retain=True en el broker).
Cuando llega un mensaje:
  - accion='activada'   → llama on_alerta(payload)
  - accion='desactivada' → llama on_desactivar()

Ventaja vs polling: el reproductor recibe la alerta INMEDIATAMENTE, sin esperar
al próximo ciclo de sync (que puede ser de 5 minutos).

Si paho-mqtt no está instalado o el broker no está disponible, el listener
falla silenciosamente y las alertas llegarán igual en el siguiente sync.

NOTA: Requiere Mosquitto broker corriendo (misma IP que MQTT_HOST).
"""

import json
import logging
import threading
from typing import Callable

import config

logger = logging.getLogger(__name__)

try:
    import paho.mqtt.client as mqtt
    _PAHO_OK = True
except ImportError:
    _PAHO_OK = False
    logger.warning('paho-mqtt no instalado — listener MQTT deshabilitado.')

# Alertas para todas las pantallas.
_TOPIC_ALERTA = 'spui/alertas/emergencia'

# Alertas dirigidas sólo a este reproductor. Se completa con el reproductor_id
# que llega en el primer sync (mismo criterio que 'spui/telemetria/{id}').
_TOPIC_ALERTA_REPRODUCTOR = 'spui/alertas/reproductor/{}'


def nuevo_cliente_mqtt(
    client_id: str,
    clean_session: bool = True,
    reproductor_id: int | None = None,
):
    """
    Crea un cliente MQTT compatible con paho-mqtt 1.x y 2.x.

    paho-mqtt 2.0 introdujo un primer argumento obligatorio, CallbackAPIVersion,
    y cambió la firma de los callbacks. Instanciar como en 1.x contra la 2.x
    lanza ValueError. Como los hilos MQTT capturan las excepciones y sólo
    loguean un warning, ese fallo dejaría la telemetría y las alertas sin
    funcionar de forma silenciosa.

    Se pide explícitamente VERSION1 para conservar las firmas de callback
    existentes (client, userdata, flags, rc), que es la vía de migración
    documentada por paho.

    Si se pasa reproductor_id, se configuran las credenciales: el broker ya no
    acepta conexiones anónimas. El usuario depende del id porque las ACLs se
    escriben como patrones sobre %u, de modo que cada equipo sólo pueda publicar
    en sus propios topics.
    """
    try:
        from paho.mqtt.enums import CallbackAPIVersion   # sólo existe en 2.x
        cliente = mqtt.Client(
            CallbackAPIVersion.VERSION1,
            client_id=client_id,
            clean_session=clean_session,
        )
    except ImportError:
        cliente = mqtt.Client(client_id=client_id, clean_session=clean_session)

    if reproductor_id is not None:
        cliente.username_pw_set(
            config.mqtt_usuario(reproductor_id),
            config.MQTT_PASS,
        )

    return cliente


class MqttListener(threading.Thread):
    def __init__(
        self,
        host: str,
        port: int,
        on_alerta: Callable[[dict], None],
        on_desactivar: Callable[[], None],
    ):
        super().__init__(daemon=True, name='mqtt-listener')
        self._host = host
        self._port = port
        self._on_alerta = on_alerta
        self._on_desactivar = on_desactivar
        self._client = None
        # Se conoce recién después del primer sync, igual que en telemetría.
        self._reproductor_id: int | None = None
        # El hilo espera acá hasta que el primer sync traiga el id: sin id no
        # hay usuario MQTT y el broker rechaza la conexión.
        self._id_listo = threading.Event()
        self._stop = threading.Event()

    def set_reproductor_id(self, reproductor_id: int) -> None:
        """
        Fija el id y destraba la conexión al broker.

        Se llama después del primer sync. Desde que el broker exige
        credenciales, el usuario es 'spui-repro-{id}', así que hasta tener el id
        no se puede ni conectar: run() espera acá.

        Si el id cambia con el cliente ya conectado (reproductor recreado en el
        CMS), se reconecta con las credenciales nuevas en vez de sólo suscribir:
        el usuario forma parte de la sesión MQTT y no se puede cambiar en
        caliente.
        """
        if self._reproductor_id == reproductor_id:
            return

        cambio = self._reproductor_id is not None
        self._reproductor_id = reproductor_id

        if cambio and self._client is not None:
            logger.info(
                'MQTT: el reproductor_id cambió a %d — reconectando con las '
                'credenciales nuevas.',
                reproductor_id,
            )
            try:
                # Corta loop_forever(); el bucle de run() vuelve a conectar.
                self._client.disconnect()
            except Exception as exc:
                logger.warning('MQTT: no se pudo cerrar la conexión previa: %s', exc)
            return

        self._id_listo.set()

    def run(self) -> None:
        if not _PAHO_OK:
            return

        # Sin reproductor_id no hay usuario MQTT válido. Se espera al primer
        # sync exitoso; si el CMS no responde, este hilo queda esperando sin
        # consumir nada y las alertas llegan igual por REST cuando haya red.
        logger.info('MQTT listener esperando el reproductor_id del primer sync...')
        while not self._stop.is_set():
            if self._id_listo.wait(timeout=5.0):
                break
        if self._stop.is_set():
            return

        # loop_forever() reconecta solo mientras el broker esté ahí, pero
        # retorna si la conexión se cierra desde este lado (cambio de id) o si
        # el connect() inicial falla. El bucle externo cubre esos dos casos:
        # sin él, un broker que arranca después que el reproductor dejaba las
        # alertas muertas hasta el próximo reinicio del servicio.
        while not self._stop.is_set():
            try:
                self._client = nuevo_cliente_mqtt(
                    self._client_id(),
                    reproductor_id=self._reproductor_id,
                )
                self._client.on_connect    = self._on_connect
                self._client.on_message    = self._on_message
                self._client.on_disconnect = self._on_disconnect
                self._client.connect(self._host, self._port, keepalive=60)
                logger.info(
                    'MQTT listener conectado a %s:%d como %s',
                    self._host, self._port, config.mqtt_usuario(self._reproductor_id),
                )
                self._client.loop_forever()
            except Exception as exc:
                logger.warning(
                    'MQTT listener no pudo conectar a %s:%d — %s '
                    '(las alertas llegarán sólo por sync REST).',
                    self._host, self._port, exc,
                )

            if self._stop.is_set():
                break

            # Reintento espaciado: un broker caído no debe generar un bucle de
            # reconexión que llene el log ni consuma CPU.
            self._stop.wait(timeout=30.0)

    def _client_id(self) -> str:
        """
        Identificador de esta conexión ante el broker.

        MQTT desconecta al cliente anterior cuando otro se conecta con el mismo
        id. Antes era el literal 'spui-reproductor' en todos los equipos, así
        que dos Pi se desconectaban mutuamente en un bucle infinito. Al ir
        atado al reproductor_id, cada equipo tiene el suyo.
        """
        return f'spui-reproductor-{self._reproductor_id}'

    def detener(self) -> None:
        self._stop.set()
        # Destraba el hilo si todavía está esperando el primer sync.
        self._id_listo.set()
        if self._client:
            self._client.disconnect()

    # ── Callbacks MQTT ────────────────────────────────────────────────────

    def _on_connect(self, client, userdata, flags, rc):
        if rc != 0:
            logger.error('MQTT connect rechazado: rc=%s', rc)
            return

        # Las suscripciones se rehacen en cada reconexión: el broker no las
        # conserva con clean_session=True.
        client.subscribe(_TOPIC_ALERTA, qos=1)
        logger.info('MQTT suscrito a: %s', _TOPIC_ALERTA)

        if self._reproductor_id is not None:
            topic = _TOPIC_ALERTA_REPRODUCTOR.format(self._reproductor_id)
            client.subscribe(topic, qos=1)
            logger.info('MQTT suscrito a: %s', topic)

    def _on_message(self, client, userdata, msg):
        try:
            crudo = msg.payload.decode('utf-8').strip()

            # El CMS borra el mensaje retenido publicando un payload vacío
            # (así lo define MQTT). No es un error: es el aviso de que ya no
            # hay alerta vigente en ese topic.
            if not crudo:
                logger.info('MQTT: mensaje retenido limpiado en %s.', msg.topic)
                return

            payload = json.loads(crudo)
            accion  = payload.get('accion')

            if accion == 'activada':
                logger.warning('MQTT: Alerta de emergencia recibida — id=%s', payload.get('id'))
                # Ejecutar en thread separado para no bloquear el loop MQTT
                self._lanzar('mostrar alerta', self._on_alerta, payload)

            elif accion == 'desactivada':
                logger.info('MQTT: Alerta desactivada.')
                self._lanzar('desactivar alerta', self._on_desactivar)

            else:
                logger.warning('MQTT: payload desconocido: %s', payload)

        except Exception as exc:
            logger.error('MQTT: Error procesando mensaje: %s', exc)

    @staticmethod
    def _lanzar(descripcion: str, funcion, *args) -> None:
        """
        Ejecuta el callback en un hilo aparte, sin bloquear el loop de MQTT.

        El envoltorio no es opcional: una excepción dentro de un hilo suelto no
        la ve nadie — ni se registra ni llega al proceso principal. La acción
        simplemente no ocurría y no quedaba rastro de por qué.
        """
        def _correr():
            try:
                funcion(*args)
            except Exception:
                logger.exception('MQTT: falló al %s.', descripcion)

        threading.Thread(target=_correr, daemon=True, name=f'mqtt-{descripcion}').start()

    def _on_disconnect(self, client, userdata, rc):
        # rc se formatea con %s y no con %d: en paho 2.x puede llegar un objeto
        # ReasonCode en lugar de un entero, y %d lo haría fallar justo acá,
        # dentro de un callback de la librería.
        if rc != 0:
            logger.warning('MQTT desconectado inesperadamente (rc=%s) — reconectando...', rc)
