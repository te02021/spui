#!/usr/bin/env python3
"""
SPUI Pi Client — Cliente multimedia para reproductores Raspberry Pi.

Arquitectura de threads:
  Main thread   → loop de sync + decisión de qué reproducir
  heartbeat     → daemon, POST /reproductores/heartbeat cada 60s
  mqtt-listener → daemon, suscrito a alertas, estado (LWT) y comandos
                  ('spui/{alertas,estado,comandos}/reproductor/{id}')
  telemetria    → daemon, publica métricas del sistema vía MQTT cada 60s

Flujo por ciclo (Fase 7 — offline/fail-safe):
  1. NetworkMonitor.is_online() — TCP check rápido (no HTTP)
     OFFLINE → servir del caché SQLite, reintentar en OFFLINE_RETRY_SEG (30s)
     ONLINE  → POST /reproductores/sync, guardar en caché, prefetch de todos los archivos
  2. Decidir qué reproducir:
     alerta activa    → player.mostrar_alerta()
     programación     → player.reproducir_playlist()
     nada             → player.mostrar_fallback()
  3. Esperar hasta el próximo sync — interrumpible por Ctrl+C/SIGTERM o por un
     comando 'sync_ahora' del CMS (tarea 1.5, push instantáneo: cambios de
     programación/playlist/contenido/pantalla llegan en segundos, no esperan
     los 300s del ciclo normal, que sigue como red de seguridad si MQTT cae).

Estrategia de fail-safe:
  - Siempre guardar el último sync exitoso en SQLite.
  - Prefetch de archivos multimedia después de cada sync online.
  - Cuando se vuelve la red: re-sync inmediato sin esperar el intervalo.
  - El player nunca se detiene: usa lo que tenga disponible en caché.
"""

import logging
import os
import random
import signal
import sys
import threading
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import config
from spui.cache import Cache
from spui.energia import GestorEnergia
from spui.heartbeat import HeartbeatSender
from spui.mqtt_listener import MqttListener
from spui.network import NetworkMonitor
from spui.player import Player
from spui.sync import SyncClient
from spui.telemetria import TelemetriaPublisher

# Cuántos segundos esperar entre reintentos cuando estamos offline
_OFFLINE_RETRY_SEG = 30


class _RegistroProblemas:
    """
    Problemas transitorios que se informan al CMS en el próximo heartbeat.

    La idea es que ningún fallo quede sólo en el log local: si un contenido no
    se puede reproducir o el caché está dañado, el operador tiene que verlo en
    el panel sin entrar por SSH a la Pi.

    Cada problema se guarda bajo una clave; volver a reportar la misma clave
    pisa el mensaje anterior en lugar de acumular repetidos. Los problemas
    caducan solos: si dejan de reportarse, desaparecen del panel en el próximo
    heartbeat, así no quedan avisos viejos de algo ya resuelto.
    """

    # Un problema se sigue informando este tiempo desde la última vez que ocurrió.
    _VIGENCIA_SEG = 180

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._items: dict[str, tuple[str, float]] = {}

    def reportar(self, clave: str, mensaje: str) -> None:
        with self._lock:
            self._items[clave] = (mensaje, time.monotonic())

    def resolver(self, clave: str) -> None:
        """Marca un problema como superado (por ejemplo, volvió la red)."""
        with self._lock:
            self._items.pop(clave, None)

    def consultar(self) -> list[str]:
        ahora = time.monotonic()
        with self._lock:
            vigentes = {
                k: v for k, v in self._items.items()
                if ahora - v[1] <= self._VIGENCIA_SEG
            }
            self._items = vigentes
            return [mensaje for mensaje, _ in vigentes.values()]


_PROBLEMAS = _RegistroProblemas()

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

# Interrumpe la espera entre ciclos (_esperar_interruptible) antes de tiempo.
# Dos motivos la disparan: una señal de cierre (para no tardar hasta 300s en
# apagarse) y un 'sync_ahora' por MQTT (tarea 1.5 — push instantáneo).
_despertar = threading.Event()

def _manejar_senal(signum, frame) -> None:
    global _corriendo
    logger.info('Señal %d recibida — cerrando SPUI client.', signum)
    _corriendo = False
    _despertar.set()


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
    player  = Player(config.MEDIA_DIR, sync_client=sync)
    network = NetworkMonitor(config.API_URL)
    energia = GestorEnergia()

    def recolectar_diagnostico() -> list[str]:
        """
        Problemas que este reproductor detecta sobre sí mismo y manda al CMS en
        cada heartbeat, para que se vean en el panel.

        La regla es que nada falle en silencio: el equipo puede estar
        conectado, sincronizando y mandando telemetría, y aun así no mostrar
        nada en pantalla. Si eso pasa tiene que verse desde el CMS y no sólo
        en el journal de la Pi.
        """
        problemas: list[str] = []

        if player.motivo_simulacion:
            problemas.append('No se está mostrando contenido en pantalla. ' + player.motivo_simulacion)

        if player.problema_pantalla:
            problemas.append(player.problema_pantalla)

        if energia.en_simulacion():
            problemas.append(
                'Sin control de encendido/apagado de la pantalla: faltan cec-client y vcgencmd. '
                'El horario energético se registra pero no se aplica.'
            )

        problemas.extend(_PROBLEMAS.consultar())

        return problemas

    heartbeat = HeartbeatSender(
        sync,
        config.HEARTBEAT_INTERVAL,
        recolectar_diagnostico=recolectar_diagnostico,
    )
    heartbeat.start()

    def sync_ahora(payload: dict) -> None:
        """
        Tarea 1.5 — el CMS avisó que algo que nos afecta cambió. Se corre en
        un hilo aparte (MqttListener._lanzar), así que dormir acá no bloquea
        ni el loop de MQTT ni al hilo principal.

        Jitter 0-2s: si el cambio afecta a muchas pantallas (por ejemplo una
        playlist compartida), el CMS publica a todas casi al mismo tiempo —
        sin este margen, todas le pegarían a /sync en el mismo instante.
        """
        time.sleep(random.uniform(0, 2))
        logger.info('Sync inmediato (motivo=%s).', payload.get('motivo'))
        _despertar.set()

    mqtt = MqttListener(
        host=config.MQTT_HOST,
        port=config.MQTT_PORT,
        on_alerta=player.mostrar_alerta,
        on_desactivar=player.desactivar_alerta,
        on_sync_ahora=sync_ahora,
    )
    mqtt.start()

    # sync_client habilita el respaldo por HTTP: si el broker se cae o nadie
    # está ingiriendo los mensajes, la telemetría igual llega al CMS.
    # network_monitor reusa el mismo NetworkMonitor de más arriba para medir
    # latencia_red_ms — no tiene sentido armar una segunda conexión de prueba.
    telemetria = TelemetriaPublisher(
        config.MQTT_HOST,
        config.MQTT_PORT,
        config.TELEMETRIA_INTERVAL,
        sync_client=sync,
        network_monitor=network,
    )
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

                # Informar cuál es este reproductor: la telemetría lo necesita
                # para publicar en su topic, y el listener MQTT para suscribirse
                # a las alertas dirigidas sólo a este reproductor.
                reproductor_id = sync_data.get('reproductor_id')
                if reproductor_id:
                    telemetria.set_reproductor_id(reproductor_id)
                    mqtt.set_reproductor_id(reproductor_id)

                # Prefetch proactivo — descarga todo lo que necesita la playlist
                # mientras la red esté disponible, para que el caché esté completo
                # antes de que la red se pierda.
                sync.prefetch_media(sync_data)

                _PROBLEMAS.resolver('sync')

            except Exception as exc:
                logger.warning('Sync falló estando online: %s — usando caché.', exc)
                _PROBLEMAS.reportar(
                    'sync',
                    f'No se pudo sincronizar con el CMS ({exc}). Se está reproduciendo desde el caché local.',
                )
                sync_data = cache.obtener_sync()
        else:
            # Offline: usar caché y reintentar pronto
            sync_data = cache.obtener_sync()
            espera    = _OFFLINE_RETRY_SEG

        if sync_data is None:
            logger.error('Sin datos disponibles (ni red ni caché). Reintentando en 30s.')
            _PROBLEMAS.reportar(
                'sin-datos',
                'Sin programación disponible: no hay conexión con el CMS y el caché local está vacío. '
                'Se muestra la pantalla de espera.',
            )
            player.mostrar_fallback()
            _esperar_interruptible(30)
            continue

        # ── Decidir qué reproducir ────────────────────────────────────────
        # El sync devuelve una lista de pantallas (una Pi puede, en teoría,
        # controlar más de una); en la práctica cada reproductor controla
        # exactamente una, así que se usa la primera. 'playlist' y
        # 'programacion_activa' son hermanos dentro de cada entrada de
        # pantallas[], no están anidados el uno en el otro.
        pantallas     = sync_data.get('pantallas') or []
        pantalla_data = pantallas[0] if pantallas else {}
        alerta        = sync_data.get('alerta_emergencia')
        playlist      = pantalla_data.get('playlist')

        if not pantallas:
            # Sin pantallas no hay nada que resolver. Se avisa con el motivo
            # concreto: si no, el fallback permanente parece "no hay
            # programación ahora" cuando en realidad falta configurar el CMS.
            logger.warning(
                'Este reproductor no tiene ninguna pantalla asignada en el CMS. '
                'Asignásela desde el formulario de la Pantalla (campo "Reproductor").'
            )
            _PROBLEMAS.reportar(
                'sin-pantallas',
                'Este reproductor no tiene ninguna pantalla asignada. Asignásela desde el '
                'formulario de la Pantalla, campo "Reproductor".',
            )
        else:
            _PROBLEMAS.resolver('sin-pantallas')

        # ── Energía (CU-11) ───────────────────────────────────────────────
        # Se aplica en cada ciclo: el sync trae el horario y el gestor decide
        # si toca encender, apagar o cambiar el brillo. Es idempotente.
        # Una emergencia enciende la pantalla aunque el horario diga apagar:
        # avisar de una evacuación pesa más que el ahorro energético.
        # Todo lo que sigue se hace bajo try: un contenido roto (un archivo que
        # no se puede leer, un campo que llega distinto de lo esperado) tiene
        # que costar como mucho un ciclo, no el proceso entero. Sin esto, una
        # sola excepción mataba el cliente, systemd lo reiniciaba, volvía a
        # fallar con el mismo contenido y la pantalla quedaba muerta.
        try:
            # Si se arrancó sin entorno gráfico (X11 todavía no estaba listo),
            # se reintenta en cada ciclo hasta conseguirlo. Sin esto el
            # reproductor quedaba en simulación hasta el próximo reinicio.
            player.reintentar_pantalla()

            energia.actualizar_reglas(pantalla_data.get('energia'))
            energia.aplicar(hay_alerta=bool(alerta))

            # El sync es la fuente de verdad sobre si hay alerta vigente: si no
            # trae ninguna y el player todavía cree que sí, hay que apagarla.
            #
            # Sin esto la alerta quedaba pegada. desactivar_alerta() sólo se
            # invocaba desde el callback MQTT, así que un reproductor que
            # estuvo offline justo cuando se desactivó la alerta perdía ese
            # mensaje, y al reconectar el retenido ya había sido limpiado por
            # el CMS. La pantalla volvía a la playlist porque reproducir_playlist()
            # la reescribe, pero _alerta seguía seteado y esta_mostrando_alerta()
            # devolvía True para siempre: la próxima alerta real no se mostraba,
            # porque el bloque de abajo la daba por ya visible.
            if not alerta and player.esta_mostrando_alerta():
                logger.info('El sync no reporta alerta activa: se desactiva la que estaba en pantalla.')
                player.desactivar_alerta()

            if alerta:
                if not player.esta_mostrando_alerta():
                    player.mostrar_alerta(alerta)
            elif energia.esta_apagada():
                logger.info('Fuera de horario: pantalla apagada, sin reproducir.')
                _esperar_interruptible(min(espera, 60))
                continue
            elif playlist and playlist.get('items'):
                player.reproducir_playlist(playlist['items'], sync_client=sync)
            else:
                # Cubre tanto "sin programación" como "playlist vacía". El
                # fallback es permanente: queda en pantalla hasta que haya
                # contenido real. Si tuviera una duración, al vencerse la
                # pantalla volvería a quedar en negro.
                if playlist:
                    logger.info('La playlist programada no tiene contenidos.')
                else:
                    logger.info('Sin programación activa ahora mismo.')
                player.mostrar_fallback()

        except Exception as exc:
            logger.exception('Error reproduciendo (se continúa en el próximo ciclo): %s', exc)
            _PROBLEMAS.reportar(
                'reproduccion',
                f'Error al reproducir el contenido programado ({exc}). Se reintenta en el próximo ciclo.',
            )

        _esperar_interruptible(espera)

    # ── Cierre limpio ────────────────────────────────────────────────────────
    player.detener()
    heartbeat.detener()
    mqtt.detener()
    telemetria.detener()
    cache.close()
    logger.info('SPUI Client detenido correctamente.')


def _esperar_interruptible(segundos: float) -> None:
    """
    Espera hasta `segundos`, o menos si algo dispara _despertar antes: una
    señal de cierre (Ctrl+C/SIGTERM) o un 'sync_ahora' por MQTT (tarea 1.5).

    Antes era un polling manual en incrementos de 0.5s sólo para poder cortar
    con Ctrl+C. Event.wait() ya resuelve eso mejor (una sola espera, sin
    despertar el proceso 600 veces en un ciclo de 300s) y de paso da el
    enganche que hacía falta para el sync instantáneo, sin agregar un
    mecanismo nuevo.
    """
    _despertar.wait(timeout=segundos)
    _despertar.clear()


if __name__ == '__main__':
    main()
