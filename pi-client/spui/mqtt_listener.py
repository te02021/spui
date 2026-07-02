"""
Listener MQTT para alertas de emergencia en tiempo real.

Suscribe al topic 'spui/alertas/emergencia' (retain=True en el broker).
Cuando llega un mensaje:
  - accion='activada'   → llama on_alerta(payload)
  - accion='desactivada' → llama on_desactivar()

Ventaja vs polling: el nodo recibe la alerta INMEDIATAMENTE, sin esperar
al próximo ciclo de sync (que puede ser de 5 minutos).

Si paho-mqtt no está instalado o el broker no está disponible, el listener
falla silenciosamente y las alertas llegarán igual en el siguiente sync.

NOTA: Requiere Mosquitto broker corriendo (misma IP que MQTT_HOST).
"""

import json
import logging
import threading
from typing import Callable

logger = logging.getLogger(__name__)

try:
    import paho.mqtt.client as mqtt
    _PAHO_OK = True
except ImportError:
    _PAHO_OK = False
    logger.warning('paho-mqtt no instalado — listener MQTT deshabilitado.')

_TOPIC_ALERTA = 'spui/alertas/emergencia'


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

    def run(self) -> None:
        if not _PAHO_OK:
            return

        try:
            self._client = mqtt.Client(client_id='spui-nodo', clean_session=True)
            self._client.on_connect    = self._on_connect
            self._client.on_message    = self._on_message
            self._client.on_disconnect = self._on_disconnect
            self._client.connect(self._host, self._port, keepalive=60)
            logger.info('MQTT listener conectado a %s:%d', self._host, self._port)
            # loop_forever() maneja reconexión automática
            self._client.loop_forever()
        except Exception as exc:
            logger.warning(
                'MQTT listener no pudo conectar a %s:%d — %s '
                '(las alertas llegarán sólo por sync REST).',
                self._host, self._port, exc,
            )

    def detener(self) -> None:
        if self._client:
            self._client.disconnect()

    # ── Callbacks MQTT ────────────────────────────────────────────────────

    def _on_connect(self, client, userdata, flags, rc):
        if rc == 0:
            client.subscribe(_TOPIC_ALERTA, qos=1)
            logger.info('MQTT suscrito a: %s', _TOPIC_ALERTA)
        else:
            logger.error('MQTT connect rechazado: rc=%d', rc)

    def _on_message(self, client, userdata, msg):
        try:
            payload = json.loads(msg.payload.decode('utf-8'))
            accion  = payload.get('accion')

            if accion == 'activada':
                logger.warning('MQTT: Alerta de emergencia recibida — id=%s', payload.get('id'))
                # Ejecutar en thread separado para no bloquear el loop MQTT
                threading.Thread(
                    target=self._on_alerta,
                    args=(payload,),
                    daemon=True,
                ).start()

            elif accion == 'desactivada':
                logger.info('MQTT: Alerta desactivada.')
                threading.Thread(target=self._on_desactivar, daemon=True).start()

            else:
                logger.warning('MQTT: payload desconocido: %s', payload)

        except Exception as exc:
            logger.error('MQTT: Error procesando mensaje: %s', exc)

    def _on_disconnect(self, client, userdata, rc):
        if rc != 0:
            logger.warning('MQTT desconectado inesperadamente (rc=%d) — reconectando...', rc)
