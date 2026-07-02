"""
Reproductor multimedia para nodo SPUI.

Tipos de contenido soportados:
  imagen  → imagen estática (PNG, JPG) mostrada N segundos
  video   → archivo de video reproducido N segundos
  texto   → texto mostrado con overlay VLC marquee
  youtube → URL de YouTube reproducida con VLC
  qr      → imagen PNG del QR (descargada del CMS)

Modo simulación:
  Si VLC no está instalado (dev en Windows sin VLC), el player imprime
  lo que reproduciría y espera 1-3 segundos. Toda la lógica de scheduling,
  alertas y sincronización funciona igual — sólo falta el output de pantalla.

NOTA Pi-only:
  La salida HDMI fullscreen requiere entorno de escritorio o Raspberry Pi OS.
  En modo headless (sin display), VLC muestra "no video output available".
  Para producción: iniciar X11 o usar Raspberry Pi OS Desktop + autostart.
"""

import logging
import os
import threading
import time
from typing import Optional

logger = logging.getLogger(__name__)

# VLC es opcional — si no está instalado se usa modo simulación
try:
    import vlc  # python-vlc
    _VLC_OK = True
    logger.info('python-vlc disponible — reproductor real activo.')
except ImportError:
    _VLC_OK = False
    logger.warning('python-vlc no instalado — modo SIMULACIÓN (sin output de pantalla).')


class Player:
    """
    Reproductor thread-safe para items de playlist y alertas de emergencia.

    Las alertas tienen prioridad máxima: el método reproducir_playlist()
    detecta la flag y retorna anticipadamente para que main.py procese la alerta.
    """

    # Tiempo máximo de espera simulada en dev (evita tests lentos)
    _MAX_SIM_WAIT = 3

    def __init__(self, media_dir: str):
        self._media_dir = media_dir
        self._lock = threading.Lock()
        self._alerta: Optional[dict] = None
        self._stop = threading.Event()
        self._vlc: Optional['vlc.Instance'] = None
        self._media_player: Optional['vlc.MediaPlayer'] = None

        if _VLC_OK:
            self._vlc = vlc.Instance(
                '--fullscreen',
                '--no-video-title-show',
                '--quiet',
                '--no-osd',
            )
            self._media_player = self._vlc.media_player_new()

    # ── API pública ────────────────────────────────────────────────────────

    def reproducir_playlist(self, items: list[dict], sync_client=None) -> None:
        """Itera los items de la playlist una vez. Sale si se activa una alerta."""
        if not items:
            self.mostrar_fallback(duracion=30)
            return

        for item in items:
            if self._tiene_alerta() or self._stop.is_set():
                return
            self._reproducir_item(item, sync_client)

    def mostrar_alerta(self, alerta: dict) -> None:
        """Override inmediato: muestra la alerta hasta que se desactive."""
        with self._lock:
            self._alerta = alerta

        titulo  = alerta.get('titulo', 'ALERTA DE EMERGENCIA')
        mensaje = alerta.get('mensaje', '')
        logger.warning('ALERTA: %s', titulo)

        self._reproducir_texto(f'{titulo}\n\n{mensaje}', duracion=None, es_alerta=True)

    def desactivar_alerta(self) -> None:
        with self._lock:
            self._alerta = None
        logger.info('Alerta desactivada — volviendo a programación normal.')
        self._parar_vlc()

    def mostrar_fallback(self, duracion: int = 30) -> None:
        """Pantalla de espera cuando no hay programación activa."""
        logo = os.path.join(os.path.dirname(__file__), '..', 'assets', 'logo_fallback.png')
        if _VLC_OK and os.path.exists(logo):
            self._reproducir_imagen(logo, duracion)
        else:
            logger.info('[FALLBACK] Sin programación por %ds.', duracion)
            self._esperar(min(duracion, self._MAX_SIM_WAIT) if not _VLC_OK else duracion)

    def esta_mostrando_alerta(self) -> bool:
        with self._lock:
            return self._alerta is not None

    def detener(self) -> None:
        self._stop.set()
        self._parar_vlc()

    # ── Reproducción por tipo ──────────────────────────────────────────────

    def _reproducir_item(self, item: dict, sync_client) -> None:
        contenido = item.get('contenido', {})
        tipo      = contenido.get('tipo', '')
        # La API devuelve duracion_efectiva_seg (ya resuelto por el servidor)
        duracion  = item.get('duracion_efectiva_seg', 10)

        logger.info('Reproduciendo [%s] "%s" (%ds)', tipo, contenido.get('titulo', ''), duracion)

        if tipo == 'texto':
            self._reproducir_texto(contenido.get('contenido_texto', ''), duracion)

        elif tipo in ('imagen', 'video', 'qr'):
            # url_descarga es la URL completa: http://servidor/api/spui/media/filename.ext
            url_descarga = contenido.get('url_descarga', '')
            local = self._resolver_local(url_descarga, contenido, sync_client)
            if local:
                if tipo == 'video':
                    self._reproducir_video(local, duracion)
                else:
                    self._reproducir_imagen(local, duracion)
            else:
                logger.warning('Archivo no disponible: %s — espera %ds.', url_descarga, duracion)
                self._esperar(duracion)

        elif tipo == 'youtube':
            self._reproducir_youtube(contenido.get('contenido_texto', ''), duracion)

        else:
            logger.warning('Tipo desconocido: %s', tipo)
            self._esperar(duracion)

    def _resolver_local(self, url_descarga: str, contenido: dict, sync_client) -> Optional[str]:
        """
        Devuelve la ruta local del archivo multimedia.
        url_descarga es la URL completa devuelta por el API (url_descarga).
        Extrae el filename de la URL para calcular la ruta local.
        """
        if not url_descarga:
            return None
        nombre = url_descarga.rstrip('/').split('/')[-1]
        local  = os.path.join(self._media_dir, nombre)
        if os.path.exists(local):
            return local
        if sync_client is None:
            logger.warning('Archivo no cacheado y sin red: %s', nombre)
            return None
        try:
            return sync_client.descargar_media(url_descarga, contenido.get('hash_archivo'))
        except Exception as exc:
            logger.error('Error descargando %s: %s', nombre, exc)
            return None

    # ── VLC wrappers ──────────────────────────────────────────────────────

    def _reproducir_imagen(self, path: str, duracion: int) -> None:
        if not _VLC_OK:
            logger.info('[SIM] imagen: %s', os.path.basename(path))
            self._esperar(min(duracion, self._MAX_SIM_WAIT))
            return
        media = self._vlc.media_new(path)
        media.add_option(':image-duration=-1')
        self._media_player.set_media(media)
        self._media_player.play()
        self._esperar(duracion)
        self._parar_vlc()

    def _reproducir_video(self, path: str, duracion: int) -> None:
        if not _VLC_OK:
            logger.info('[SIM] video: %s', os.path.basename(path))
            self._esperar(min(duracion, self._MAX_SIM_WAIT))
            return
        media = self._vlc.media_new(path)
        self._media_player.set_media(media)
        self._media_player.play()
        self._esperar(duracion)
        self._parar_vlc()

    def _reproducir_texto(self, texto: str, duracion: Optional[int], es_alerta: bool = False) -> None:
        label = 'ALERTA' if es_alerta else 'TEXTO'
        if not _VLC_OK:
            logger.info('[SIM] %s: %s', label, texto[:120])
            if duracion:
                self._esperar(min(duracion, self._MAX_SIM_WAIT))
            else:
                while self._tiene_alerta() and not self._stop.is_set():
                    time.sleep(0.5)
            return

        media = self._vlc.media_new('blank://')
        media.add_option(':sub-filter=marq')
        media.add_option(f':marq-marquee={texto}')
        media.add_option(':marq-position=8')   # centro
        media.add_option(':marq-size=48')
        if es_alerta:
            media.add_option(':marq-color=0xFF0000')

        self._media_player.set_media(media)
        self._media_player.play()

        if duracion:
            self._esperar(duracion)
        else:
            while self._tiene_alerta() and not self._stop.is_set():
                time.sleep(0.5)

        self._parar_vlc()

    def _reproducir_youtube(self, url: str, duracion: int) -> None:
        if not _VLC_OK:
            logger.info('[SIM] youtube: %s', url)
            self._esperar(min(duracion, self._MAX_SIM_WAIT))
            return
        media = self._vlc.media_new(url)
        self._media_player.set_media(media)
        self._media_player.play()
        self._esperar(duracion)
        self._parar_vlc()

    # ── Utilidades ────────────────────────────────────────────────────────

    def _esperar(self, segundos: float) -> None:
        """Espera N segundos en incrementos de 0.5s revisando alertas y stop."""
        deadline = time.monotonic() + segundos
        while time.monotonic() < deadline:
            if self._tiene_alerta() or self._stop.is_set():
                return
            time.sleep(0.5)

    def _tiene_alerta(self) -> bool:
        with self._lock:
            return self._alerta is not None

    def _parar_vlc(self) -> None:
        if _VLC_OK and self._media_player:
            try:
                self._media_player.stop()
            except Exception:
                pass
