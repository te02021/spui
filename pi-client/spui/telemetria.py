"""
TelemetriaPublisher — publica métricas del sistema vía MQTT cada N segundos.

Métricas recopiladas:
  temperatura_soc_celsius : sensor térmico del Pi (/sys/class/thermal) o psutil.
                            None en Windows/simulación sin sensor.
  uso_cpu_porcentaje      : uso de CPU en %, promedio desde la lectura anterior
                            (noviembre 2026; un CMS viejo lo ignora).
  uso_ram_porcentaje      : RAM usada en % (psutil).
  espacio_disco_libre_mb  : espacio libre en la partición raíz en MB (psutil).
  latencia_red_ms         : TCP connect al host del CMS, en ms (NetworkMonitor).
                            None si no se pudo conectar en el momento de medir.

El reproductor_id se establece externamente vía set_reproductor_id() después del primer sync.
El thread no publica hasta tener un reproductor_id válido.

Modo en vivo (29/10/2026): mientras alguien mira la telemetría de este
reproductor en el CMS, el CMS manda el comando 'telemetria_vivo' y este hilo
publica además temperatura, CPU y RAM cada 1 s en spui/telemetria/vivo/{id}. Ese
topic tiene dos niveles después de spui/telemetria/, así que el daemon del CMS
(suscrito a spui/telemetria/+) no lo guarda: va directo al navegador. El CMS
renueva el pedido mientras la página está abierta; si deja de renovarlo, el
modo en vivo vence solo y queda la publicación normal de cada minuto.
"""

import json
import logging
import os
import threading
import time

logger = logging.getLogger('spui.telemetria')

try:
    import psutil
    _PSUTIL_OK = True
except ImportError:
    _PSUTIL_OK = False
    logger.warning('psutil no disponible — métricas de RAM/disco simuladas en 0.')

class MedidorCpu:
    """
    Uso de CPU entre una lectura y la anterior, en %.

    Con su propio punto de partida y no con psutil.cpu_percent(), que guarda
    uno solo para todo el proceso: la lectura en vivo (cada 1 s) le robaría
    el intervalo a la de cada minuto, que dejaría de ser el promedio del minuto.
    """

    def __init__(self, tiempos=None):
        self._tiempos = tiempos or (psutil.cpu_times if _PSUTIL_OK else None)
        self._anterior = self._leer_tiempos()

    def _leer_tiempos(self):
        if self._tiempos is None:
            return None
        try:
            t = self._tiempos()
            ocioso = t.idle + getattr(t, 'iowait', 0.0)
            return sum(t), ocioso
        except Exception:
            return None

    def leer(self) -> float | None:
        actual = self._leer_tiempos()
        anterior, self._anterior = self._anterior, actual
        if actual is None or anterior is None:
            return None
        total, ocioso = actual[0] - anterior[0], actual[1] - anterior[1]
        if total <= 0:
            return None
        return round(max(0.0, min(100.0, 100.0 * (1 - ocioso / total))), 1)


try:
    import paho.mqtt.client as mqtt          # noqa: F401 — chequeo de disponibilidad
    from .mqtt_listener import nuevo_cliente_mqtt
    _MQTT_OK = True
except ImportError:
    _MQTT_OK = False


#: Tope de un pedido de modo en vivo: aunque el CMS pida más, a los 5 min
#: sin renovar se vuelve a la publicación normal (una página que quedó abierta
#: en otra pestaña no puede dejar a la Pi publicando cada segundo para siempre).
VIVO_MAX_SEG = 300
VIVO_INTERVALO_SEG = 1.0

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
        network_monitor=None,
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
        # NetworkMonitor ya existente en main.py — se reusa para medir
        # latencia_red_ms en vez de armar una conexión de prueba aparte.
        self._network  = network_monitor
        self._fallos_mqtt = 0
        self._usando_http = False
        # Uso de CPU: uno para la publicación de cada minuto y otro para la
        # en vivo, así cada uno promedia su propio intervalo (MedidorCpu).
        self._cpu = MedidorCpu()
        self._cpu_vivo = MedidorCpu()
        # Modo en vivo: hasta cuándo (time.monotonic()) publicar cada segundo.
        self._vivo_hasta = 0.0
        # Despierta el ciclo antes de tiempo: al detener o al pedir modo en vivo.
        self._senal = threading.Event()

    def set_reproductor_id(self, reproductor_id: int) -> None:
        self._reproductor_id = reproductor_id

    def detener(self) -> None:
        self._stop.set()
        self._senal.set()

    def vivo(self, segundos) -> None:
        """Publicar temperatura y RAM cada 1 s durante `segundos` (comando del CMS)."""
        try:
            segundos = float(segundos)
        except (TypeError, ValueError):
            segundos = 60.0
        segundos = max(1.0, min(segundos, VIVO_MAX_SEG))
        nuevo = time.monotonic() + segundos
        if nuevo > self._vivo_hasta:
            if not self.en_vivo():
                logger.info('Telemetría en vivo: cada %.0f s durante %.0f s.', VIVO_INTERVALO_SEG, segundos)
            self._vivo_hasta = nuevo
        self._senal.set()

    def en_vivo(self) -> bool:
        return time.monotonic() < self._vivo_hasta

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

        proxima = time.monotonic() + self._interval
        estaba_en_vivo = False
        while not self._stop.is_set():
            ahora = time.monotonic()
            espera = proxima - ahora
            if self.en_vivo():
                espera = min(espera, VIVO_INTERVALO_SEG)
            self._senal.wait(timeout=max(0.0, espera))
            self._senal.clear()
            if self._stop.is_set():
                break
            if self._reproductor_id is None:
                continue  # esperar al primer sync exitoso

            # Todo el ciclo va dentro del try, incluida la lectura de métricas:
            # si una excepción escapara de acá, mataría este hilo en silencio y
            # la telemetría dejaría de publicarse para siempre, sin que el
            # proceso principal se entere ni quede nada en el log.
            try:
                if time.monotonic() >= proxima:
                    proxima = time.monotonic() + self._interval
                    self._publicar(client, self._recopilar())
                if self.en_vivo():
                    self._publicar_vivo(client)
                    estaba_en_vivo = True
                elif estaba_en_vivo:
                    estaba_en_vivo = False
                    logger.info('Telemetría en vivo terminada: nadie la está mirando. Se vuelve a cada %d s.', self._interval)
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
                    'Telemetría → %s | temp=%s°C cpu=%s%% ram=%.1f%% disco=%dMB',
                    topic,
                    f'{payload["temperatura_soc_celsius"]:.1f}'
                    if payload['temperatura_soc_celsius'] is not None else 'N/A',
                    payload.get('uso_cpu_porcentaje') if payload.get('uso_cpu_porcentaje') is not None else 'N/A',
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

    def _publicar_vivo(self, client) -> None:
        """
        Una muestra del modo en vivo: sólo MQTT, QoS 0 y sin respaldo HTTP. Es
        para mirar en el momento; una muestra perdida se reemplaza al segundo.
        """
        if client is None:
            return
        payload = {
            'temperatura_soc_celsius': self._leer_temperatura(),
            'uso_cpu_porcentaje':      self._cpu_vivo.leer(),
            'uso_ram_porcentaje':      self._leer_ram(),
            'espacio_disco_libre_mb':  self._leer_disco(),
            'espacio_disco_total_mb':  self._leer_disco_total(),
            'ts':                      round(time.time(), 1),
        }
        client.publish(f'spui/telemetria/vivo/{self._reproductor_id}', json.dumps(payload), qos=0)

    # ── Recopilación de métricas ──────────────────────────────────────────────

    def _recopilar(self) -> dict:
        return {
            'reproductor_id':         self._reproductor_id,
            'temperatura_soc_celsius': self._leer_temperatura(),
            # Promedio desde la publicación anterior (un minuto). Opcional:
            # un CMS viejo lo ignora.
            'uso_cpu_porcentaje':     self._cpu.leer(),
            'uso_ram_porcentaje':     self._leer_ram(),
            'espacio_disco_libre_mb': self._leer_disco(),
            # Para el indicador circular del CMS (% usado). Opcional: un CMS
            # viejo lo ignora.
            'espacio_disco_total_mb': self._leer_disco_total(),
            'latencia_red_ms':        self._network.medir_latencia_ms() if self._network else None,
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

    def _leer_disco_total(self) -> int | None:
        if _PSUTIL_OK:
            try:
                particion = '/' if os.name != 'nt' else 'C:\\'
                return psutil.disk_usage(particion).total // (1024 * 1024)
            except Exception:
                pass
        return None
