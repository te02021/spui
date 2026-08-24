"""
TelemetriaPublisher — publica métricas del sistema vía MQTT cada N segundos.

Métricas recopiladas:
  temperatura_soc_celsius : sensor térmico del Pi (/sys/class/thermal) o psutil.
                            None en Windows/simulación sin sensor.
  uso_ram_porcentaje      : RAM usada en % (psutil).
  espacio_disco_libre_mb  : espacio libre en la partición raíz en MB (psutil).
  latencia_red_ms         : reservado (siempre None por ahora).

El reproductor_id se establece externamente vía set_reproductor_id() después del primer sync.
El thread no publica hasta tener un reproductor_id válido.
"""

import json
import logging
import os
import threading

logger = logging.getLogger('spui.telemetria')

try:
    import psutil
    _PSUTIL_OK = True
except ImportError:
    _PSUTIL_OK = False
    logger.warning('psutil no disponible — métricas de RAM/disco simuladas en 0.')

try:
    import paho.mqtt.client as mqtt          # noqa: F401 — chequeo de disponibilidad
    from .mqtt_listener import nuevo_cliente_mqtt
    _MQTT_OK = True
except ImportError:
    _MQTT_OK = False


#: Publicaciones MQTT fallidas seguidas antes de recurrir a HTTP.
#: Tres ciclos (3 min con el intervalo por defecto) es suficiente para
#: distinguir un corte real de un hipo momentáneo del broker, sin cambiar de
#: canal por una reconexión que se resuelve sola.
_FALLOS_ANTES_DE_HTTP = 3


class TelemetriaPublisher(threading.Thread):
    """
    Thread daemon que publica telemetría del sistema.

    MQTT es la vía principal: es el caso de uso canónico del protocolo —métricas
    periódicas, pérdida tolerable, fire-and-forget— y no carga al servidor web
    con una request por minuto y por reproductor.

    Pero MQTT tiene un modo de fallo silencioso: si el broker se cae, o si el
    daemon que ingiere los mensajes no está corriendo, el publish "funciona" sin
    error y los datos desaparecen. Por eso, tras varios fallos seguidos, se cae
    a HTTP, que sí confirma la recepción. En cuanto MQTT vuelve, se retoma.
    """

    def __init__(
        self,
        mqtt_host: str,
        mqtt_port: int,
        interval: int = 60,
        sync_client=None,
    ) -> None:
        super().__init__(name='telemetria', daemon=True)
        self._host     = mqtt_host
        self._port     = mqtt_port
        self._interval = interval
        self._reproductor_id: int | None = None
        self._stop     = threading.Event()
        # SyncClient, para el respaldo por HTTP. Si no se pasa, el publisher
        # funciona igual pero sin red de seguridad.
        self._sync     = sync_client
        self._fallos_mqtt = 0
        self._usando_http = False

    def set_reproductor_id(self, reproductor_id: int) -> None:
        self._reproductor_id = reproductor_id

    def detener(self) -> None:
        self._stop.set()

    def run(self) -> None:
        if not _MQTT_OK:
            logger.warning('Telemetría deshabilitada: paho-mqtt no está instalado.')
            return

        # El broker ya no acepta conexiones anónimas y el usuario es
        # 'spui-repro-{id}', así que hay que esperar al primer sync antes de
        # conectar. Antes se conectaba al arrancar y sólo se esperaba el id para
        # publicar; ahora el id hace falta también para autenticarse.
        logger.info('TelemetriaPublisher esperando el reproductor_id del primer sync...')
        while not self._stop.is_set() and self._reproductor_id is None:
            self._stop.wait(timeout=5.0)
        if self._stop.is_set():
            return

        client = None
        try:
            client = nuevo_cliente_mqtt(
                f'spui-telemetria-{self._reproductor_id}',
                reproductor_id=self._reproductor_id,
            )
            client.connect(self._host, self._port, keepalive=60)
            client.loop_start()
        except Exception as exc:
            # Antes esto hacía return y mataba el hilo: si el broker no estaba
            # arriba en el momento exacto del arranque, el equipo se quedaba sin
            # telemetría hasta el próximo reinicio del servicio. Ahora se sigue
            # adelante con client=None y el ciclo publica por HTTP.
            logger.error(
                'No se pudo conectar al broker MQTT para telemetría: %s '
                '(verificá que el CMS haya creado la credencial de este reproductor). '
                'Se usará HTTP mientras tanto.',
                exc,
            )
            self._fallos_mqtt = _FALLOS_ANTES_DE_HTTP

        logger.info('TelemetriaPublisher iniciado — publicando cada %ds a %s:%d.',
                    self._interval, self._host, self._port)

        while not self._stop.wait(timeout=self._interval):
            if self._reproductor_id is None:
                continue  # esperar al primer sync exitoso

            # Todo el ciclo va dentro del try, incluida la lectura de métricas:
            # si una excepción escapara de acá, mataría este hilo en silencio y
            # la telemetría dejaría de publicarse para siempre, sin que el
            # proceso principal se entere ni quede nada en el log.
            try:
                payload = self._recopilar()
                self._publicar(client, payload)
            except Exception as exc:
                logger.warning('Error publicando telemetría: %s', exc)

        if client is not None:
            client.loop_stop()
            client.disconnect()
        logger.info('TelemetriaPublisher detenido.')

    def _publicar(self, client, payload: dict) -> None:
        """
        Publica por MQTT y, si falla repetidamente, por HTTP.

        `client.publish()` con QoS 0 no confirma nada: devuelve un resultado
        local que sólo dice si el mensaje se pudo encolar. Alcanza para detectar
        que la conexión se cayó, que es el caso que importa acá; no detecta que
        el broker esté recibiendo pero nadie ingiriendo, para lo cual haría
        falta QoS 1 y no lo justifica una métrica que se repite cada minuto.
        """
        topic = f'spui/telemetria/{self._reproductor_id}'
        exito = False

        # Se sigue intentando MQTT en cada ciclo aunque ya se haya caído a HTTP:
        # paho reconecta solo en segundo plano, así que el primer publish que
        # vuelva a funcionar devuelve al canal principal. Si se dejara de
        # intentar al llegar al tope, el equipo quedaría en HTTP para siempre
        # aunque el broker se hubiera recuperado a los dos minutos.
        if client is not None:
            try:
                info = client.publish(topic, json.dumps(payload), qos=0)
                # rc == 0 es MQTT_ERR_SUCCESS; cualquier otro valor significa que
                # ni siquiera se encoló (típicamente por conexión perdida).
                exito = getattr(info, 'rc', 0) == 0
            except Exception as exc:
                logger.debug('Publish MQTT lanzó excepción: %s', exc)
                exito = False

            if exito:
                if self._usando_http:
                    logger.info('Telemetría: MQTT se recuperó, se vuelve a ese canal.')
                    self._usando_http = False
                self._fallos_mqtt = 0
                logger.debug(
                    'Telemetría → %s | temp=%s°C ram=%.1f%% disco=%dMB',
                    topic,
                    f'{payload["temperatura_soc_celsius"]:.1f}'
                    if payload['temperatura_soc_celsius'] is not None else 'N/A',
                    payload['uso_ram_porcentaje'],
                    payload['espacio_disco_libre_mb'],
                )
                return

            # Se acota al umbral: con el broker caído durante horas, el contador
            # crecería sin techo y el log repetiría un número cada vez más
            # grande que no aporta nada.
            self._fallos_mqtt = min(self._fallos_mqtt + 1, _FALLOS_ANTES_DE_HTTP)
            if not self._usando_http:
                logger.warning(
                    'Telemetría: fallo MQTT %d/%d.',
                    self._fallos_mqtt, _FALLOS_ANTES_DE_HTTP,
                )

            # Por debajo del umbral se acepta perder esta muestra en vez de
            # cambiar de canal: un corte de un ciclo suele ser una reconexión
            # que se resuelve sola, y la telemetría tolera huecos.
            if self._fallos_mqtt < _FALLOS_ANTES_DE_HTTP:
                return

        # ── Respaldo por HTTP ────────────────────────────────────────────────
        if self._sync is None:
            logger.warning(
                'Telemetría perdida: MQTT no está disponible y no hay cliente HTTP '
                'de respaldo configurado.'
            )
            return

        if not self._usando_http:
            logger.warning(
                'Telemetría: %d fallos MQTT seguidos — se pasa a HTTP. '
                'Se seguirá intentando MQTT en cada ciclo.',
                self._fallos_mqtt,
            )
            self._usando_http = True

        if self._sync.enviar_telemetria(payload):
            logger.debug('Telemetría enviada por HTTP (respaldo).')
        else:
            logger.warning('Telemetría perdida: fallaron MQTT y HTTP.')

    # ── Recopilación de métricas ──────────────────────────────────────────────

    def _recopilar(self) -> dict:
        return {
            'reproductor_id':         self._reproductor_id,
            'temperatura_soc_celsius': self._leer_temperatura(),
            'uso_ram_porcentaje':     self._leer_ram(),
            'espacio_disco_libre_mb': self._leer_disco(),
            'latencia_red_ms':        None,
        }

    def _leer_temperatura(self) -> float | None:
        # Pi: sensor térmico expuesto como archivo de texto (mili-°C)
        thermal_path = '/sys/class/thermal/thermal_zone0/temp'
        if os.path.exists(thermal_path):
            try:
                with open(thermal_path) as f:
                    return round(int(f.read().strip()) / 1000.0, 2)
            except Exception:
                pass
        # Fallback: psutil (útil en x86 Linux con sensores ACPI)
        if _PSUTIL_OK:
            try:
                temps = psutil.sensors_temperatures()
                for key in ('cpu_thermal', 'coretemp', 'acpitz', 'k10temp'):
                    if key in temps and temps[key]:
                        return round(temps[key][0].current, 2)
            except AttributeError:
                pass  # Windows no tiene sensors_temperatures
        return None  # sin sensor disponible (simulación)

    def _leer_ram(self) -> float:
        # Igual que los otros lectores: una métrica que no se puede leer vale
        # 0.0, no una excepción. Es información de monitoreo, no algo por lo
        # que valga la pena cortar el ciclo.
        if _PSUTIL_OK:
            try:
                return round(psutil.virtual_memory().percent, 2)
            except Exception:
                pass
        return 0.0

    def _leer_disco(self) -> int:
        if _PSUTIL_OK:
            try:
                particion = '/' if os.name != 'nt' else 'C:\\'
                return psutil.disk_usage(particion).free // (1024 * 1024)
            except Exception:
                pass
        return 0
