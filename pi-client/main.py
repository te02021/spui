#!/usr/bin/env python3
"""
SPUI Pi Client — Cliente multimedia para reproductores Raspberry Pi.

Arquitectura de threads:
  Main thread   → loop de sync + decisión de qué reproducir
  heartbeat     → daemon, POST /reproductores/heartbeat cada 60s
  mqtt-listener → daemon, suscrito a 'spui/alertas/emergencia'
  telemetria    → daemon, publica métricas del sistema vía MQTT cada 60s

Flujo por ciclo (Fase 7 — offline/fail-safe):
  1. NetworkMonitor.is_online() — TCP check rápido (no HTTP)
     OFFLINE → servir del caché SQLite, reintentar en OFFLINE_RETRY_SEG (30s)
     ONLINE  → POST /reproductores/sync, guardar en caché, prefetch de todos los archivos
  2. Decidir qué reproducir:
     alerta activa    → player.mostrar_alerta()
     programación     → player.reproducir_playlist()
     nada             → player.mostrar_fallback()
  3. Esperar hasta el próximo sync (interruptible).

Estrategia de fail-safe:
  - Siempre guardar el último sync exitoso en SQLite.
  - Prefetch de archivos multimedia después de cada sync online.
  - Cuando se vuelve la red: re-sync inmediato sin esperar el intervalo.
  - El player nunca se detiene: usa lo que tenga disponible en caché.
"""

import logging
import os
import signal
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import config
from spui.cache import Cache
from spui.heartbeat import HeartbeatSender
from spui.mqtt_listener import MqttListener
from spui.network import NetworkMonitor
from spui.player import Player
from spui.sync import SyncClient
from spui.telemetria import TelemetriaPublisher

# Cuántos segundos esperar entre reintentos cuando estamos offline
_OFFLINE_RETRY_SEG = 30

# ── Logging ───────────────────────────────────────────────────────────────────

def _setup_logging() -> None:
    fmt      = '%(asctime)s [%(levelname)s] %(name)s: %(message)s'
    level    = getattr(logging, config.LOG_LEVEL.upper(), logging.INFO)
    handlers = [logging.StreamHandler(sys.stdout)]

    if os.path.isabs(config.LOG_FILE):
        try:
            os.makedirs(os.path.dirname(config.LOG_FILE), exist_ok=True)
            handlers.append(logging.FileHandler(config.LOG_FILE))
        except (OSError, PermissionError):
            pass

    logging.basicConfig(level=level, format=fmt, handlers=handlers, force=True)

# ── Entry point ───────────────────────────────────────────────────────────────

logger = logging.getLogger('spui.main')

_corriendo = True

def _manejar_senal(signum, frame) -> None:
    global _corriendo
    logger.info('Señal %d recibida — cerrando SPUI client.', signum)
    _corriendo = False


def main() -> None:
    _setup_logging()

    if not config.API_KEY:
        logger.error('SPUI_API_KEY no configurada. Ver /etc/spui/spui.env')
        sys.exit(1)

    os.makedirs(config.CACHE_DIR, exist_ok=True)
    os.makedirs(config.MEDIA_DIR, exist_ok=True)

    signal.signal(signal.SIGTERM, _manejar_senal)
    signal.signal(signal.SIGINT, _manejar_senal)

    cache   = Cache(config.DB_PATH)
    sync    = SyncClient(config.API_URL, config.API_KEY, config.MEDIA_DIR)
    player  = Player(config.MEDIA_DIR)
    network = NetworkMonitor(config.API_URL)

    heartbeat = HeartbeatSender(sync, config.HEARTBEAT_INTERVAL)
    heartbeat.start()

    mqtt = MqttListener(
        host=config.MQTT_HOST,
        port=config.MQTT_PORT,
        on_alerta=player.mostrar_alerta,
        on_desactivar=player.desactivar_alerta,
    )
    mqtt.start()

    telemetria = TelemetriaPublisher(config.MQTT_HOST, config.MQTT_PORT, config.TELEMETRIA_INTERVAL)
    telemetria.start()

    logger.info('SPUI Client iniciado. API: %s', config.API_URL)

    while _corriendo:
        sync_data = None
        espera    = config.SYNC_INTERVAL  # por defecto: 300s

        # ── Sync con detección de red (Fase 7) ───────────────────────────
        if network.is_online():
            try:
                sync_data = sync.sincronizar()
                cache.guardar_sync(sync_data)

                # Informar al publisher de telemetría cuál es este reproductor
                reproductor_id = sync_data.get('reproductor_id')
                if reproductor_id:
                    telemetria.set_reproductor_id(reproductor_id)

                # Prefetch proactivo — descarga todo lo que necesita la playlist
                # mientras la red esté disponible, para que el caché esté completo
                # antes de que la red se pierda.
                sync.prefetch_media(sync_data)

            except Exception as exc:
                logger.warning('Sync falló estando online: %s — usando caché.', exc)
                sync_data = cache.obtener_sync()
        else:
            # Offline: usar caché y reintentar pronto
            sync_data = cache.obtener_sync()
            espera    = _OFFLINE_RETRY_SEG

        if sync_data is None:
            logger.error('Sin datos disponibles (ni red ni caché). Reintentando en 30s.')
            player.mostrar_fallback(duracion=30)
            _esperar_interruptible(30)
            continue

        # ── Decidir qué reproducir ────────────────────────────────────────
        alerta = sync_data.get('alerta_emergencia')
        prog   = sync_data.get('programacion_activa')

        if alerta:
            if not player.esta_mostrando_alerta():
                player.mostrar_alerta(alerta)
        elif prog:
            items = prog.get('playlist', {}).get('items', [])
            player.reproducir_playlist(items, sync_client=sync)
        else:
            logger.info('Sin programación activa ahora mismo.')
            player.mostrar_fallback(duracion=espera)

        _esperar_interruptible(espera)

    # ── Cierre limpio ────────────────────────────────────────────────────────
    player.detener()
    heartbeat.detener()
    mqtt.detener()
    telemetria.detener()
    cache.close()
    logger.info('SPUI Client detenido correctamente.')


def _esperar_interruptible(segundos: float) -> None:
    """Espera N segundos en incrementos de 0.5s para poder interrumpir con Ctrl+C."""
    deadline = time.monotonic() + segundos
    while time.monotonic() < deadline and _corriendo:
        time.sleep(0.5)


if __name__ == '__main__':
    main()
