#!/usr/bin/env python3
"""
SPUI Pi Client — Cliente multimedia para reproductores Raspberry Pi.

Arquitectura de threads:
  Main thread   → loop de sync + decisión de qué reproducir
  reproduccion  → daemon, el ÚNICO que usa VLC: muestra la alerta o gira la
                  playlist sin pausa (spui/reproduccion.py)
  heartbeat     → daemon, POST /reproductores/heartbeat cada 60s
  mqtt-listener → daemon, suscrito a alertas, estado (LWT) y comandos
                  ('spui/{alertas,estado,comandos}/reproductor/{id}')
  telemetria    → daemon, publica métricas del sistema vía MQTT cada 60s

Flujo por ciclo (Fase 7 — offline/fail-safe):
  1. NetworkMonitor.is_online() — TCP check rápido (no HTTP)
     OFFLINE → servir del caché SQLite, reintentar en OFFLINE_RETRY_SEG (30s)
     ONLINE  → POST /reproductores/sync, guardar en caché, prefetch de todos los archivos
  2. Decidir qué reproducir y avisárselo al hilo de reproducción, que lo
     cambia en el acto si es distinto de lo que está mostrando:
     alerta activa    → reproduccion.alerta_segun_sync()
     programación     → reproduccion.programar(PLAYLIST, items)
     nada             → reproduccion.programar(ESPERA)
     fuera de horario → reproduccion.programar(APAGADO)
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
from spui.guardia import GuardiaContenido
from spui.energia import GestorEnergia
from spui.heartbeat import HeartbeatSender
from spui.mqtt_listener import MqttListener
from spui.network import NetworkMonitor
from spui.player import Player
from spui.reproduccion import APAGADO, ESPERA, PLAYLIST, Reproduccion
from spui.sync import SyncClient, intervalo_desde_sync
from spui.telemetria import TelemetriaPublisher

# Cuántos segundos esperar entre reintentos cuando estamos offline
_OFFLINE_RETRY_SEG = 30
# Dispersión al azar del reintento (± segundos). Si se corta la red de todo un
# edificio, al volver las Pis no tienen que reintentar todas en el mismo
# instante (efecto manada): la ráfaga de 50 syncs simultáneos fue la prueba que
# más RAM le pidió al servidor (docs/pruebas/2026-10-13_analisis.md §3.3).
_OFFLINE_JITTER_SEG = 10


def _espera_reintento() -> float:
    """Segundos hasta el próximo reintento sin red: 30 ± 10, distinto en cada Pi y en cada vez."""
    return _OFFLINE_RETRY_SEG + random.uniform(-_OFFLINE_JITTER_SEG, _OFFLINE_JITTER_SEG)


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

# Interrumpe la espera entre ciclos (_esperar_interruptible) antes de tiempo:
# una señal de cierre, un 'sync_ahora' por MQTT (tarea 1.5 — push instantáneo)
# o una alerta por MQTT (para volver a aplicar el horario energético).
_despertar = threading.Event()

# Además de despertar, pide un sync. Una alerta por MQTT despierta SIN pedirlo:
# ya trae lo que hace falta, y cada sync de más consume el límite del CMS
# (4 por minuto); el 24/10/2026 un cambio de playlist recibía 429 porque los
# había gastado activar y desactivar una alerta.
_pedido_sync = threading.Event()

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
    sync.limpiar_parciales()  # descargas a medias de una ejecución anterior
    guardia = GuardiaContenido(config.CACHE_DIR, config.VERSION)
    player  = Player(config.MEDIA_DIR, sync_client=sync, guardia=guardia)
    reproduccion = Reproduccion(player, sync_client=sync)
    network = NetworkMonitor(config.API_URL)
    # El brillo de referencia de cada pantalla se guarda en el caché para
    # sobrevivir a reinicios; si la pantalla no deja controlar su brillo, el
    # player oscurece la imagen (spui/brillo.py).
    energia = GestorEnergia(
        ruta_estado_brillo=os.path.join(config.CACHE_DIR, 'brillo.json'),
        brillo_software=player.ajustar_brillo,
    )

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

        # Qué no se puede controlar de la pantalla (CEC, señal, brillo) y
        # cualquier encendido/apagado o brillo que haya fallado.
        problemas.extend(energia.diagnostico())

        # Contenidos que cerraron el reproductor y se están salteando.
        problemas.extend(guardia.problemas())

        problemas.extend(_PROBLEMAS.consultar())

        return problemas

    heartbeat = HeartbeatSender(
        sync,
        config.HEARTBEAT_INTERVAL,
        recolectar_diagnostico=recolectar_diagnostico,
    )
    heartbeat.start()

    # Las alertas por MQTT van al hilo de reproducción, nunca directo a VLC
    # (spui/reproduccion.py). Se despierta además al ciclo principal: un sync
    # en el momento aplica el horario energético (una alerta enciende la
    # pantalla aunque sea fuera de hora, y al terminar vuelve a apagarla).
    def alerta_y_despertar(alerta: dict) -> None:
        reproduccion.alerta(alerta)
        _despertar.set()

    def desactivar_alerta_y_despertar() -> None:
        reproduccion.quitar_alerta()
        _despertar.set()

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
        _pedido_sync.set()
        _despertar.set()

    # sync_client habilita el respaldo por HTTP: si el broker se cae o nadie
    # está ingiriendo los mensajes, la telemetría igual llega al CMS.
    # network_monitor reusa el mismo NetworkMonitor de más arriba para medir
    # latencia_red_ms — no tiene sentido armar una segunda conexión de prueba.
    # Se crea antes que el listener MQTT, que le pasa el comando 'telemetria_vivo'.
    telemetria = TelemetriaPublisher(
        config.MQTT_HOST,
        config.MQTT_PORT,
        config.TELEMETRIA_INTERVAL,
        sync_client=sync,
        network_monitor=network,
    )

    mqtt = MqttListener(
        host=config.MQTT_HOST,
        port=config.MQTT_PORT,
        on_alerta=alerta_y_despertar,
        on_desactivar=desactivar_alerta_y_despertar,
        on_sync_ahora=sync_ahora,
        on_telemetria_vivo=lambda payload: telemetria.vivo(payload.get('segundos')),
    )
    reproduccion.start()
    mqtt.start()
    telemetria.start()

    logger.info('SPUI Client iniciado (versión %s). API: %s', config.VERSION, config.API_URL)

    # Cada cuánto sincronizar: arranca con SPUI_SYNC_INTERVAL de spui.env y
    # después lo fija el CMS en cada sync (se configura por reproductor).
    intervalo_sync = config.SYNC_INTERVAL
    # Cuándo fue el último sync que respondió (monotonic; 0 = ninguno todavía):
    # para que un sync sacado del caché no reviva una alerta ya desactivada.
    ultimo_sync_ok = 0.0
    # Cuándo toca el próximo sync (monotonic) y lo último que se supo del CMS:
    # un ciclo despertado sólo por una alerta usa esto y no sincroniza.
    proximo_sync = 0.0
    ultimo_sync_data = None

    while _corriendo:
        hacer_sync = _pedido_sync.is_set() or time.monotonic() >= proximo_sync or ultimo_sync_data is None
        _pedido_sync.clear()
        sync_data = None
        espera    = intervalo_sync

        # ── Sync con detección de red (Fase 7) ───────────────────────────
        sync_ok = False   # el CMS respondió en este ciclo (ver energía, más abajo)
        if not hacer_sync:
            sync_data = ultimo_sync_data
        elif network.is_online():
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
                sync_ok = True
                ultimo_sync_ok = time.monotonic()
                intervalo_sync = intervalo_desde_sync(sync_data, intervalo_sync)
                espera = intervalo_sync

            except Exception as exc:
                espera = _espera_tras_fallo(exc)
                logger.warning('Sync falló estando online: %s — usando caché; se reintenta en %d s.', exc, round(espera))
                _PROBLEMAS.reportar(
                    'sync',
                    f'No se pudo sincronizar con el CMS ({exc}). Se está reproduciendo desde el caché local.',
                )
                sync_data = cache.obtener_sync()
        else:
            # Offline: usar caché y reintentar pronto
            sync_data = cache.obtener_sync()
            espera    = _espera_reintento()

        if hacer_sync:
            proximo_sync = time.monotonic() + espera
            if sync_data is not None:
                ultimo_sync_data = sync_data

        if sync_data is None:
            espera = _espera_reintento()
            proximo_sync = time.monotonic() + espera
            logger.error('Sin datos disponibles (ni red ni caché). Reintentando en %d s.', round(espera))
            _PROBLEMAS.reportar(
                'sin-datos',
                'Sin programación disponible: no hay conexión con el CMS y el caché local está vacío. '
                'Se muestra la pantalla de espera.',
            )
            reproduccion.programar(ESPERA)
            _esperar_hasta(proximo_sync)
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
            # El sync es la verdad sobre la alerta, salvo que venga del caché:
            # ver Reproduccion.alerta_segun_sync(). En un ciclo sin sync (lo
            # despertó una alerta por MQTT) no hay nada nuevo que decir de ella.
            if hacer_sync:
                reproduccion.alerta_segun_sync(alerta, fresco=sync_ok, sync_ok_desde=ultimo_sync_ok)

            energia.actualizar_reglas(pantalla_data.get('energia'))
            estaba_apagada = energia.esta_apagada()
            # Con el sync recién hecho, el cliente tiene la verdad del CMS: es el
            # momento de comparar el brillo de la pantalla con el de la regla.
            energia.aplicar(hay_alerta=reproduccion.mostrando_alerta(), verificar_brillo=sync_ok)
            if estaba_apagada and not energia.esta_apagada():
                player.forzar_recarga()

            # Para dibujar el TV: el lugar del pie de la plantilla y desde qué
            # hora el tema automático pasa a claro y a oscuro.
            player.fijar_entorno(pantalla_data.get('lugar'), sync_data.get('tema_horario'))

            # Qué mostrar cuando no hay alerta. Lo muestra el hilo de
            # reproducción: este ciclo nunca se queda esperando un contenido,
            # así un cambio en el CMS se aplica en el acto, haya alerta o no.
            if energia.esta_apagada():
                reproduccion.programar(APAGADO)
            elif playlist and playlist.get('items'):
                reproduccion.programar(PLAYLIST, playlist['items'])
            else:
                # Sin programación o con la playlist vacía: la pantalla de
                # espera, que queda fija hasta que haya contenido real.
                reproduccion.programar(ESPERA)

        except Exception as exc:
            logger.exception('Error aplicando el sync (se continúa en el próximo ciclo): %s', exc)
            _PROBLEMAS.reportar(
                'reproduccion',
                f'Error al aplicar la programación ({exc}). Se reintenta en el próximo ciclo.',
            )

        _esperar_hasta(proximo_sync)

    # ── Cierre limpio ────────────────────────────────────────────────────────
    reproduccion.detener()
    heartbeat.detener()
    mqtt.detener()
    telemetria.detener()
    cache.close()
    logger.info('SPUI Client detenido correctamente.')


def _espera_tras_fallo(exc: Exception) -> float:
    """
    Cuánto esperar después de un sync fallido con red. Un 429 (límite de syncs
    del CMS) trae Retry-After: se reintenta apenas se pueda, para que el cambio
    que motivó el sync llegue en segundos y no recién en el próximo intervalo.
    Cualquier otro fallo, como sin red: 30 ± 10 s.
    """
    respuesta = getattr(exc, 'response', None)
    if respuesta is not None and getattr(respuesta, 'status_code', None) == 429:
        try:
            segundos = float(respuesta.headers.get('Retry-After', ''))
        except (TypeError, ValueError):
            segundos = 15.0
        return max(1.0, min(60.0, segundos)) + random.uniform(0, 2)
    return _espera_reintento()


def _esperar_hasta(momento: float) -> None:
    """Espera hasta `momento` (monotonic), o menos si algo despierta al ciclo."""
    _esperar_interruptible(max(0.0, momento - time.monotonic()))


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
