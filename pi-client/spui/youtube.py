"""
Videos de YouTube en el reproductor.

VLC no abre un link de YouTube por sí solo: quedaba en State.Opening y la
pantalla se trababa (28/10/2026). Por eso la Pi descarga cada video con yt-dlp
y lo reproduce como un video local, igual que uno subido al CMS:

  - fluido: se pide el formato que la Pi 4 decodifica por hardware (H.264,
    hasta 1080p y 30 fps), el mismo límite que el CMS aplica a los subidos;
  - sin red: una vez bajado, queda en el caché como cualquier archivo;
  - sin frenar nada: las descargas van en este hilo, una por vez. Mientras un
    video no terminó de bajarse, la playlist lo saltea.

El archivo queda en el caché como youtube-<id>.mp4 (el id de 11 caracteres del
link, que el CMS ya guarda normalizado: SPUI\\Service\\YoutubeUrl). Se baja a una
carpeta temporal y recién terminado se mueve a su nombre final: el player nunca
ve un archivo a medias.

Requisitos en la Pi (los instala install.sh): yt-dlp con su resolvedor de
desafíos (yt-dlp[default], en el venv), un intérprete de JavaScript (Deno, o
Node como alternativa: YouTube lo exige desde 2025) y ffmpeg, que une el video
y el audio, que YouTube entrega por separado.
"""

import logging
import os
import queue
import re
import shutil
import subprocess
import sys
import threading
import time
from typing import Iterable, Optional

logger = logging.getLogger(__name__)

_ID = re.compile(r'(?:[?&]v=|youtu\.be/|/shorts/|/embed/|/live/)([A-Za-z0-9_-]{11})')

# H.264 hasta 1080p y 30 fps, con audio AAC; si no hay por separado, uno que
# ya venga con video y audio juntos, con el mismo límite.
FORMATO = ('bv*[vcodec^=avc1][height<=1080][fps<=30]+ba[acodec^=mp4a]'
           '/b[vcodec^=avc1][height<=1080][fps<=30]')
DURACION_MAXIMA_SEG = 3600          # un video de más de una hora no es contenido de cartelera
TAMANIO_MAXIMO = '1500M'
TIMEOUT_SEG = 3600
REINTENTO_SEG = 1800                # tras un fallo, se vuelve a intentar a la media hora
_TMP = '.youtube-tmp'


def id_de(url: str) -> Optional[str]:
    m = _ID.search(url or '')
    return m.group(1) if m else None


def nombre_archivo(id_video: str) -> str:
    return f'youtube-{id_video}.mp4'


def de_sync(sync_data: dict) -> list[tuple[str, str]]:
    """(link, título) de los YouTube de las playlists que trae el sync."""
    videos = []
    for pantalla in sync_data.get('pantallas') or []:
        for item in ((pantalla.get('playlist') or {}).get('items') or []):
            c = item.get('contenido') or {}
            if c.get('tipo') == 'youtube' and c.get('contenido_texto'):
                videos.append((c['contenido_texto'], c.get('titulo') or ''))
    return videos


class DescargasYoutube(threading.Thread):
    def __init__(self, media_dir: str, ejecutable: Optional[str] = None):
        super().__init__(daemon=True, name='youtube')
        self._media_dir = media_dir
        self._tmp = os.path.join(media_dir, _TMP)
        self._ejecutable = ejecutable or os.path.join(os.path.dirname(sys.executable), 'yt-dlp')
        self._cola: 'queue.Queue[tuple[str, str, str]]' = queue.Queue()
        self._lock = threading.Lock()
        self._pendientes: set[str] = set()
        self._fallas: dict[str, tuple[float, str, str]] = {}   # id → (próximo intento, título, motivo)
        self._corriendo = True
        shutil.rmtree(self._tmp, ignore_errors=True)          # descargas a medias de otra ejecución

    # ── Lo que usan los otros hilos ────────────────────────────────────────

    def ruta(self, url: str) -> Optional[str]:
        """El video ya bajado, o None si todavía no está."""
        id_video = id_de(url)
        if not id_video:
            return None
        ruta = os.path.join(self._media_dir, nombre_archivo(id_video))
        return ruta if os.path.exists(ruta) else None

    def pedir(self, videos: Iterable[tuple[str, str]]) -> None:
        """(link, título) de los YouTube de la playlist: baja los que falten, de a uno."""
        ahora = time.monotonic()
        for url, titulo in videos:
            id_video = id_de(url)
            if not id_video or self.ruta(url):
                continue
            with self._lock:
                if id_video in self._pendientes:
                    continue
                falla = self._fallas.get(id_video)
                if falla and ahora < falla[0]:
                    continue
                self._pendientes.add(id_video)
            self._cola.put((id_video, url, titulo))

    def problemas(self) -> list[str]:
        with self._lock:
            return [f'No se pudo descargar el video de YouTube «{titulo}» ({motivo}). '
                    'Se saltea en la playlist y se vuelve a intentar solo.'
                    for _, titulo, motivo in self._fallas.values()]

    def detener(self) -> None:
        self._corriendo = False

    # ── El hilo ────────────────────────────────────────────────────────────

    def run(self) -> None:
        while self._corriendo:
            try:
                id_video, url, titulo = self._cola.get(timeout=1)
            except queue.Empty:
                continue
            try:
                self._descargar(id_video, url, titulo)
            except Exception as exc:   # una descarga rota no puede tumbar el hilo
                logger.exception('Descarga de YouTube «%s» falló: %s', titulo, exc)
                self._fallo(id_video, titulo, 'error inesperado')
            finally:
                with self._lock:
                    self._pendientes.discard(id_video)

    def comando(self, url: str, destino_tmp: str) -> list[str]:
        cmd = [self._ejecutable, '--no-playlist', '--no-progress', '--no-mtime',
               '-f', FORMATO, '--merge-output-format', 'mp4',
               '--match-filter', f'duration <= {DURACION_MAXIMA_SEG}',
               '--max-filesize', TAMANIO_MAXIMO,
               '-o', os.path.join(destino_tmp, '%(id)s.%(ext)s')]
        # yt-dlp usa Deno por defecto; si sólo está Node, hay que pedirlo.
        if not shutil.which('deno') and shutil.which('node'):
            cmd += ['--js-runtimes', 'node']
        return cmd + [url]

    def _descargar(self, id_video: str, url: str, titulo: str) -> None:
        if not os.path.exists(self._ejecutable) and not shutil.which('yt-dlp'):
            self._fallo(id_video, titulo, 'yt-dlp no está instalado en la Raspberry')
            return
        destino_tmp = os.path.join(self._tmp, id_video)
        os.makedirs(destino_tmp, exist_ok=True)
        logger.info('Descargando video de YouTube «%s» (%s)…', titulo, id_video)
        inicio = time.monotonic()
        try:
            r = subprocess.run(self.comando(url, destino_tmp), capture_output=True, text=True, timeout=TIMEOUT_SEG)
        except subprocess.TimeoutExpired:
            shutil.rmtree(destino_tmp, ignore_errors=True)
            self._fallo(id_video, titulo, 'la descarga tardó más de una hora')
            return

        bajado = os.path.join(destino_tmp, f'{id_video}.mp4')
        if r.returncode != 0 or not os.path.exists(bajado):
            ultima = next((l for l in reversed((r.stderr or r.stdout or '').splitlines()) if l.strip()), '')
            logger.warning('yt-dlp no pudo bajar «%s» (código %s): %s', titulo, r.returncode, ultima)
            shutil.rmtree(destino_tmp, ignore_errors=True)
            self._fallo(id_video, titulo, _motivo_legible(ultima, r.stdout or ''))
            return

        os.replace(bajado, os.path.join(self._media_dir, nombre_archivo(id_video)))
        shutil.rmtree(destino_tmp, ignore_errors=True)
        with self._lock:
            self._fallas.pop(id_video, None)
        logger.info('Video de YouTube «%s» listo (%.0f s): entra en la playlist.', titulo, time.monotonic() - inicio)

    def _fallo(self, id_video: str, titulo: str, motivo: str) -> None:
        with self._lock:
            self._fallas[id_video] = (time.monotonic() + REINTENTO_SEG, titulo, motivo)


def _motivo_legible(stderr: str, stdout: str) -> str:
    """El motivo en criollo: va al diagnóstico del CMS, que lee alguien que no es técnico."""
    texto = (stderr + ' ' + stdout).lower()
    if 'does not pass filter' in texto or 'duration' in texto and 'filter' in texto:
        return 'dura más de una hora'
    if 'requested format is not available' in texto:
        return 'no tiene una versión en H.264 de 1080p o menos'
    if 'private video' in texto or 'sign in' in texto or 'age' in texto and 'confirm' in texto:
        return 'el video es privado o pide iniciar sesión'
    if 'unavailable' in texto or 'not available' in texto:
        return 'el video no está disponible'
    if 'file is larger than max-filesize' in texto or 'max-filesize' in texto:
        return 'el archivo es demasiado grande'
    if 'ffmpeg' in texto:
        return 'falta ffmpeg en la Raspberry'
    if 'javascript runtime' in texto or 'js runtime' in texto:
        return 'falta un intérprete de JavaScript (Deno) en la Raspberry'
    return 'yt-dlp no pudo bajarlo; el detalle está en el registro de la Raspberry'
