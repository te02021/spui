"""
TelemetriaPublisher — publica métricas del sistema vía MQTT cada N segundos.

Métricas recopiladas:
  temperatura_soc_celsius : sensor térmico del Pi (/sys/class/thermal) o psutil.
                            None en Windows/simulación sin sensor.
  uso_ram_porcentaje      : RAM usada en % (psutil).
  espacio_disco_libre_mb  : espacio libre en la partición raíz en MB (psutil).
  latencia_red_ms         : reservado (siempre None por ahora).

El nodo_id se establece externamente vía set_nodo_id() después del primer sync.
El thread no publica hasta tener un nodo_id válido.
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
    import paho.mqtt.client as mqtt
    _MQTT_OK = True
except ImportError:
    _MQTT_OK = False


class TelemetriaPublisher(threading.Thread):
    """Thread daemon que publica telemetría del sistema al broker MQTT."""

    def __init__(self, mqtt_host: str, mqtt_port: int, interval: int = 60) -> None:
        super().__init__(name='telemetria', daemon=True)
        self._host     = mqtt_host
        self._port     = mqtt_port
        self._interval = interval
        self._nodo_id: int | None = None
        self._stop     = threading.Event()

    def set_nodo_id(self, nodo_id: int) -> None:
        self._nodo_id = nodo_id

    def detener(self) -> None:
        self._stop.set()

    def run(self) -> None:
        if not _MQTT_OK:
            logger.warning('Telemetría deshabilitada: paho-mqtt no está instalado.')
            return

        try:
            client = mqtt.Client(client_id='spui-telemetria')
            client.connect(self._host, self._port, keepalive=60)
            client.loop_start()
        except Exception as exc:
            logger.error('No se pudo conectar al broker MQTT para telemetría: %s', exc)
            return

        logger.info('TelemetriaPublisher iniciado — publicando cada %ds a %s:%d.',
                    self._interval, self._host, self._port)

        while not self._stop.wait(timeout=self._interval):
            if self._nodo_id is None:
                continue  # esperar al primer sync exitoso

            payload = self._recopilar()
            topic   = f'spui/telemetria/{self._nodo_id}'
            try:
                client.publish(topic, json.dumps(payload), qos=0)
                logger.debug('Telemetría → %s | temp=%s°C ram=%.1f%% disco=%dMB',
                             topic,
                             f'{payload["temperatura_soc_celsius"]:.1f}' if payload['temperatura_soc_celsius'] is not None else 'N/A',
                             payload['uso_ram_porcentaje'],
                             payload['espacio_disco_libre_mb'])
            except Exception as exc:
                logger.warning('Error publicando telemetría MQTT: %s', exc)

        client.loop_stop()
        client.disconnect()
        logger.info('TelemetriaPublisher detenido.')

    # ── Recopilación de métricas ──────────────────────────────────────────────

    def _recopilar(self) -> dict:
        return {
            'nodo_id':                self._nodo_id,
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
        if _PSUTIL_OK:
            return round(psutil.virtual_memory().percent, 2)
        return 0.0

    def _leer_disco(self) -> int:
        if _PSUTIL_OK:
            try:
                particion = '/' if os.name != 'nt' else 'C:\\'
                return psutil.disk_usage(particion).free // (1024 * 1024)
            except Exception:
                pass
        return 0
