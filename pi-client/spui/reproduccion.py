"""
Hilo de reproducción: el ÚNICO que usa VLC.

Antes el ciclo principal sincronizaba Y reproducía, y las alertas llegaban por
MQTT y se mostraban desde el hilo de MQTT. Tres problemas reales (24/10/2026):

  - Mientras se mostraba una alerta, el ciclo principal quedaba bloqueado: un
    cambio en el CMS (sync_ahora) recién se aplicaba al desactivar la alerta.
  - Un cambio de playlist se aplicaba al terminar la vuelta en curso, que con
    un video largo podían ser minutos.
  - Dos hilos usando el mismo reproductor de VLC a la vez, una carrera que
    puede cerrar el proceso con un error nativo.

Ahora:

  - El ciclo principal (main.py) sólo sincroniza y le dice a este hilo QUÉ
    mostrar: una playlist, la pantalla de espera o nada (pantalla apagada por
    horario). Las alertas llegan por alerta()/quitar_alerta(), desde MQTT o
    desde el sync.
  - Este hilo es el que muestra, y la playlist gira sin pausa: al terminar el
    último contenido sigue el primero.
  - Si el programa cambia (otra playlist, una alerta, el apagado), se corta lo
    que se está mostrando en el acto y se pasa a lo nuevo. Si el sync trae la
    misma playlist, no se corta nada: la firma es la misma.
"""

import logging
import threading
import time
from typing import Optional

logger = logging.getLogger(__name__)

PLAYLIST = 'playlist'
ESPERA   = 'espera'     # pantalla de espera institucional (no hay nada programado)
APAGADO  = 'apagado'    # fuera del horario energético: no se reproduce nada

# Una vuelta que dura menos que esto no mostró nada de verdad (todos los
# archivos fallaron al instante): antes de repetirla se espera un poco.
_VUELTA_MINIMA_SEG = 0.5

# Un sync sin alerta no saca una alerta que llegó por MQTT hace menos que esto:
# el sync pudo haberse calculado antes de que se activara.
_GRACIA_ALERTA_SEG = 10.0


class Reproduccion(threading.Thread):
    def __init__(self, player, sync_client=None):
        super().__init__(daemon=True, name='reproduccion')
        self._player = player
        self._sync = sync_client
        self._lock = threading.Lock()
        self._programa: tuple[str, Optional[list]] = (ESPERA, None)
        self._firma: Optional[str] = None
        self._alerta: Optional[dict] = None
        self._alta_alerta = 0.0      # monotonic de la última alerta recibida
        self._baja_alerta = 0.0      # monotonic de la última desactivación
        self._cambio = threading.Event()
        self._corriendo = True

    # ── Lo que llaman los otros hilos ──────────────────────────────────────

    def programar(self, tipo: str, items: Optional[list] = None) -> None:
        """Qué mostrar cuando no hay alerta. Sólo corta lo actual si cambió."""
        firma = tipo if tipo != PLAYLIST else 'playlist:' + self._player._firma_playlist(items or [])
        with self._lock:
            if firma == self._firma:
                return
            anterior = self._programa[0]
            self._programa = (tipo, items)
            self._firma = firma
        if tipo == APAGADO:
            logger.info('Fuera de horario: pantalla apagada, sin reproducir.')
        elif tipo == ESPERA:
            logger.info('Sin programación activa ahora mismo: pantalla de espera.')
        elif anterior == PLAYLIST:
            logger.info('Cambió la playlist: se pasa a la nueva en el acto.')
        self._player.interrumpir()
        self._cambio.set()

    def alerta(self, alerta: dict) -> None:
        """Mostrar esta alerta (desde MQTT o desde el sync). La misma que ya está, se ignora."""
        if not isinstance(alerta, dict):
            logger.error('Alerta con formato inesperado (%s) — se ignora.', type(alerta).__name__)
            return
        with self._lock:
            if self._alerta is not None and self._alerta.get('id') == alerta.get('id'):
                return
            self._alerta = alerta
            self._alta_alerta = time.monotonic()
        self._player.marcar_alerta(alerta)
        self._cambio.set()

    def quitar_alerta(self) -> None:
        """Desactivada por MQTT: siempre manda, es lo más nuevo que se sabe."""
        with self._lock:
            hubo = self._alerta is not None
            self._alerta = None
            self._baja_alerta = time.monotonic()
        if hubo:
            self._player.desactivar_alerta()
            self._cambio.set()

    def alerta_segun_sync(self, alerta: Optional[dict], fresco: bool, sync_ok_desde: float) -> None:
        """
        Lo que dice el sync sobre la alerta. Un sync recién hecho es la verdad
        del CMS. Uno sacado del caché (sin red, o un 429) es viejo: puede traer
        una alerta que ya se desactivó por MQTT después de guardarlo, y no la
        puede revivir (pasó el 24/10/2026: la alerta volvía a aparecer medio
        segundo después de desactivarla). Tampoco saca nada: no sabe más que MQTT.

        `sync_ok_desde`: monotonic del último sync exitoso (0 = ninguno en esta
        ejecución, se arrancó del caché).
        """
        with self._lock:
            hay = self._alerta is not None
            baja, alta = self._baja_alerta, self._alta_alerta
        if fresco:
            if alerta:
                self.alerta(alerta)
            elif hay and time.monotonic() - alta > _GRACIA_ALERTA_SEG:
                logger.info('El sync no reporta alerta activa: se desactiva la que estaba en pantalla.')
                self.quitar_alerta()
            return
        if alerta and baja <= sync_ok_desde:
            self.alerta(alerta)

    def mostrando_alerta(self) -> bool:
        with self._lock:
            return self._alerta is not None

    def detener(self) -> None:
        self._corriendo = False
        self._player._stop.set()
        self._player.interrumpir()
        self._cambio.set()
        self.join(timeout=5)
        self._player.detener()

    # ── El hilo ────────────────────────────────────────────────────────────

    def run(self) -> None:
        while self._corriendo:
            # Primero se limpia, después se lee: un cambio que llegue en el
            # medio igual corta la vuelta que está por empezar.
            self._cambio.clear()
            self._player.limpiar_interrupcion()
            try:
                self._una_vuelta()
            except Exception as exc:   # un contenido roto cuesta una vuelta, no el hilo
                logger.exception('Error reproduciendo (se sigue con la próxima vuelta): %s', exc)
                self._cambio.wait(5)

    def _una_vuelta(self) -> None:
        self._player.reintentar_pantalla()

        with self._lock:
            alerta = self._alerta
            tipo, items = self._programa

        if alerta is not None:
            # Bloquea hasta que se desactive o la reemplace otra.
            self._player.mostrar_alerta(alerta)
            return

        if tipo == APAGADO:
            self._player._parar_vlc()
            self._cambio.wait(60)
            return

        if tipo == ESPERA or not items:
            self._player.mostrar_fallback()
            self._cambio.wait(60)
            return

        inicio = time.monotonic()
        otra_vuelta = self._player.reproducir_playlist(items, sync_client=self._sync)
        if not otra_vuelta:
            # Quedó algo fijo en pantalla (permanente o espera): hasta que cambie.
            self._cambio.wait(60)
        elif time.monotonic() - inicio < _VUELTA_MINIMA_SEG:
            # Una vuelta que termina en el acto es una playlist que no puede
            # mostrar nada (todos los archivos faltan, por ejemplo): se espera
            # un poco en vez de girar en vacío y llenar el log.
            self._cambio.wait(5)
