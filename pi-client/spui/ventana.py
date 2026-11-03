"""
La ventana del TV (noviembre 2026): una ventana pygame/SDL2 a pantalla
completa donde se muestra todo lo que dibuja spui/tv.py.

Antes cada contenido era una imagen que abría VLC, y entre una y otra la
ventana de VLC se volvía a crear: no había forma de animar nada. Ahora la
ventana es siempre la misma y es nuestra:

  - Un contenido aparece al instante, sin ventanas que se abran y cierren.
  - Las páginas del cronograma se deslizan hacia la izquierda mientras entra
    la siguiente desde la derecha, como en la vista previa del CMS (1,4 s,
    misma curva). Se redibuja sólo la tabla, no la pantalla entera.
  - El video lo sigue reproduciendo VLC, pero DENTRO de esta ventana
    (set_xwindow): no aparece otra ventana encima que el gestor de ventanas
    pueda enmarcar o dejar a medio tamaño.
  - El brillo por software (pantallas sin DDC/CI) es un velo negro encima de
    todo, que se aplica en el momento, sin volver a cargar el contenido.

Todo lo de esta clase se llama desde el hilo de reproducción
(spui/reproduccion.py), que es el que creó la ventana: SDL no admite que dos
hilos usen la misma ventana.

Se dibuja por software por defecto. SPUI_ACELERACION=1 le pide a SDL que
presente la ventana con la GPU; queda apagado porque en la Pi 4 las salidas
de VLC basadas en OpenGL creaban la ventana sin dibujar nada (agosto 2026), y
una pantalla en negro es peor que una animación menos fluida.
"""

import logging
import os
import time
from typing import Callable, Optional

logger = logging.getLogger(__name__)

# Sin el saludo de pygame en cada arranque (ensucia el journal).
os.environ.setdefault('PYGAME_HIDE_SUPPORT_PROMPT', '1')
try:
    import pygame
    PYGAME_DISPONIBLE = True
except Exception:   # no instalado, o el SDL del sistema no carga
    pygame = None
    PYGAME_DISPONIBLE = False

DESLIZAR_MS = 1400
_FPS = 60


def curva(t: float) -> float:
    """cubic-bezier(.65, 0, .35, 1), la del CSS: arranca y frena suave."""
    if t <= 0:
        return 0.0
    if t >= 1:
        return 1.0
    x1, y1, x2, y2 = .65, 0.0, .35, 1.0

    def bez(a, b, s):
        return 3 * a * (1 - s) ** 2 * s + 3 * b * (1 - s) * s ** 2 + s ** 3

    # Se busca s tal que x(s) = t (bisección: x es creciente) y se devuelve y(s).
    lo, hi = 0.0, 1.0
    for _ in range(30):
        s = (lo + hi) / 2
        if bez(x1, x2, s) < t:
            lo = s
        else:
            hi = s
    return bez(y1, y2, (lo + hi) / 2)


class _Preparada:
    """Una lámina ya convertida a superficies de pygame, al tamaño de la pantalla."""

    def __init__(self, lamina, fondo, zona, paginas, zona_avance, avances, zona_hora=None):
        self.lamina = lamina
        self.zona_hora = zona_hora
        self.hora: Optional[tuple] = None     # (hhmm, superficie) del último recuadro preparado
        self.fondo = fondo
        self.zona = zona
        self.paginas = paginas
        self.zona_avance = zona_avance
        self.avances = avances


class Ventana:
    def __init__(self, acelerada: bool = False):
        self._acelerada = acelerada
        self._sup = None
        self._tam = (0, 0)
        self._brillo = 1.0
        self._velo = None
        self._actual: Optional[tuple] = None        # (lámina, página) en pantalla
        self._cache: dict = {}                       # id(lámina) → _Preparada
        self._cubierta = False                       # VLC está dibujando encima
        self._reloj = None
        self.fps_ultimo_deslizamiento: Optional[float] = None
        # La hora en vivo (03/11/2026): la última que llegó y la que está en pantalla.
        self._hhmm: Optional[str] = None
        self._hora_en_pantalla: Optional[str] = None
        self.motivo: Optional[str] = None

    # ── Abrir y cerrar ────────────────────────────────────────────────────

    def abrir(self) -> bool:
        """Crea la ventana a pantalla completa. Devuelve False (con self.motivo) si no se pudo."""
        if not PYGAME_DISPONIBLE:
            self.motivo = 'pygame no está instalado en la Raspberry Pi (ver install.sh).'
            return False
        os.environ['SDL_FRAMEBUFFER_ACCELERATION'] = '1' if self._acelerada else '0'
        # El protector de pantalla y el apagado los maneja el horario
        # energético (spui/energia.py), no SDL.
        os.environ.setdefault('SDL_VIDEO_ALLOW_SCREENSAVER', '0')
        # La clase de la ventana para el gestor de ventanas: la regla de Openbox
        # (docs/09_instalacion_raspberry.md §8.5) la reconoce como 'spui'.
        os.environ.setdefault('SDL_VIDEO_X11_WMCLASS', 'spui')
        try:
            pygame.display.init()
            self._sup = pygame.display.set_mode((0, 0), pygame.FULLSCREEN | pygame.NOFRAME)
            pygame.display.set_caption('SPUI')
            pygame.mouse.set_visible(False)
            self._tam = self._sup.get_size()
            self._reloj = pygame.time.Clock()
            self._sup.fill((0, 0, 0))
            pygame.display.flip()
        except Exception as exc:
            self.motivo = f'No se pudo abrir la ventana del reproductor ({exc}).'
            self._sup = None
            try:
                pygame.display.quit()
            except Exception:
                pass
            return False
        logger.info('Ventana del TV abierta: %dx%d (%s).', self._tam[0], self._tam[1],
                    'presentación por GPU' if self._acelerada else 'dibujo por software')
        return True

    @property
    def abierta(self) -> bool:
        return self._sup is not None

    def id_nativo(self) -> Optional[int]:
        """El id de la ventana para VLC (X11: window; Windows: el HWND)."""
        try:
            return pygame.display.get_wm_info().get('window')
        except Exception:
            return None

    def cerrar(self) -> None:
        if self._sup is not None:
            try:
                pygame.display.quit()
            except Exception:
                pass
        self._sup = None
        self._cache.clear()

    # ── Lo que llama el player ────────────────────────────────────────────

    def cubierta(self, si: bool) -> None:
        """VLC empieza (o deja) de dibujar encima: mientras tanto no se pinta nada."""
        self._cubierta = si
        if not si:
            self._redibujar()

    def brillo(self, fraccion: float) -> None:
        nuevo = max(0.0, min(1.0, float(fraccion)))
        if abs(nuevo - self._brillo) < 0.005:
            return
        self._brillo = nuevo
        self._velo = None
        self._redibujar()

    def negro(self) -> None:
        """Pantalla apagada por horario: nada encima."""
        self._actual = None
        if self._sup is not None and not self._cubierta:
            self._sup.fill((0, 0, 0))
            pygame.display.flip()

    def mostrar(self, lamina, pagina: int = 0) -> None:
        """Pone una lámina (y la página `pagina` si es un cronograma), de golpe."""
        self._actual = (lamina, pagina)
        if self._sup is None or self._cubierta:
            return
        p = self._preparar(lamina)
        self._sup.blit(p.fondo, (0, 0))
        if p.paginas:
            self._sup.blit(p.paginas[pagina], p.zona.topleft)
        if p.avances:
            self._sup.blit(p.avances[pagina], p.zona_avance.topleft)
        self._pintar_hora(p)
        self._velar(None)
        pygame.display.flip()
        self._olvidar_viejas(lamina)

    def hora(self, hhmm: str) -> None:
        """
        La hora de ahora ('18:40', la del CMS: spui/reloj.py). Si cambió el
        minuto, se repinta sólo el recuadro de la hora de lo que está en
        pantalla. Se llama seguido mientras se espera: comparar es gratis.
        """
        self._hhmm = hhmm
        if self._sup is None or self._cubierta or self._actual is None or hhmm == self._hora_en_pantalla:
            return
        p = self._preparar(self._actual[0])
        if p.zona_hora is None:
            return
        self._pintar_hora(p)
        self._velar(p.zona_hora)
        pygame.display.update(p.zona_hora)

    def _pintar_hora(self, p: '_Preparada') -> None:
        self._hora_en_pantalla = None
        if p.zona_hora is None:
            return
        hhmm = self._hhmm or p.lamina.hora_dibujada
        if p.hora is None or p.hora[0] != hhmm:
            img = p.lamina.con_hora(hhmm)
            if img is None:
                return
            p.hora = (hhmm, self._superficie(img, p.zona_hora.size, True))
        self._sup.blit(p.hora[1], p.zona_hora.topleft)
        self._hora_en_pantalla = hhmm

    def deslizar(self, sale, i_sale: int, entra, i_entra: int, cortar: Callable[[], bool]) -> None:
        """
        La página que estaba sale hacia la izquierda y la nueva entra desde la
        derecha (translateX de 105 %), en DESLIZAR_MS. `sale` y `entra` pueden
        ser la misma lámina o una recién dibujada (otra vuelta del cronograma:
        cambió la hora y quizá alguna fila ya terminó). Si `cortar()` da True
        (una alerta, otro programa), se termina en el acto.
        """
        if self._sup is None or self._cubierta:
            self._actual = (entra, i_entra)
            return
        ps, pe = self._preparar(sale), self._preparar(entra)
        if not ps.paginas or not pe.paginas:
            self.mostrar(entra, i_entra)
            return

        zona = pe.zona
        recorrido = zona.width * 1.05
        # El fondo nuevo (otra fecha, otra cantidad de actividades, otros
        # puntos de avance) va de una: lo que se mueve es sólo la tabla.
        self._sup.blit(pe.fondo, (0, 0))
        if pe.avances:
            self._sup.blit(pe.avances[i_entra], pe.zona_avance.topleft)
        self._pintar_hora(pe)
        primero = True
        cuadros, inicio = 0, time.monotonic()
        while True:
            t = (time.monotonic() - inicio) * 1000 / DESLIZAR_MS
            if t >= 1 or cortar():
                break
            corrido = curva(t) * recorrido
            self._sup.blit(pe.fondo, zona.topleft, zona)
            self._sup.set_clip(zona)
            self._sup.blit(ps.paginas[i_sale], (round(zona.x - corrido), zona.y))
            self._sup.blit(pe.paginas[i_entra], (round(zona.x + recorrido - corrido), zona.y))
            self._sup.set_clip(None)
            if primero:
                self._velar(None)
                pygame.display.flip()
                primero = False
            else:
                self._velar(zona)
                pygame.display.update(zona)
            cuadros += 1
            pygame.event.pump()
            self._reloj.tick(_FPS)
        duracion = time.monotonic() - inicio
        if cuadros > 2 and duracion > 0:
            fps = cuadros / duracion
            if self.fps_ultimo_deslizamiento is None:
                logger.info('Deslizamiento de páginas: %.0f cuadros por segundo.', fps)
            self.fps_ultimo_deslizamiento = fps
        self.mostrar(entra, i_entra)

    def atender(self) -> None:
        """
        Atiende los avisos de la ventana. Se llama seguido mientras se espera:
        si el sistema la tapó, la cambió de tamaño (la señal HDMI vuelve con
        otra resolución después de un apagado por horario) o pidió que se
        redibuje, se vuelve a pintar lo que está en pantalla.
        """
        if self._sup is None:
            return
        redibujar = False
        try:
            for ev in pygame.event.get():
                if ev.type in (pygame.VIDEOEXPOSE, pygame.VIDEORESIZE, getattr(pygame, 'WINDOWEXPOSED', -1),
                               getattr(pygame, 'WINDOWSIZECHANGED', -1), getattr(pygame, 'WINDOWSHOWN', -1),
                               getattr(pygame, 'WINDOWRESTORED', -1)):
                    redibujar = True
        except Exception as exc:
            logger.warning('Error leyendo los avisos de la ventana: %s', exc)
            return
        sup = pygame.display.get_surface()
        if sup is not None and sup.get_size() != self._tam:
            logger.info('La pantalla cambió de tamaño: %dx%d → %dx%d.', *self._tam, *sup.get_size())
            self._sup, self._tam = sup, sup.get_size()
            self._cache.clear()
            self._velo = None
            redibujar = True
        if redibujar:
            self._redibujar()

    # ── Interno ───────────────────────────────────────────────────────────

    def _redibujar(self) -> None:
        if self._actual is not None:
            self.mostrar(*self._actual)
        elif self._sup is not None and not self._cubierta:
            self._sup.fill((0, 0, 0))
            self._velar(None)
            pygame.display.flip()

    def _velar(self, rect) -> None:
        """El velo negro del brillo por software, sobre todo o sobre `rect`."""
        if self._brillo >= 0.995:
            return
        if self._velo is None:
            self._velo = pygame.Surface(self._tam)
            self._velo.fill((0, 0, 0))
            self._velo.set_alpha(round((1 - self._brillo) * 255))
        if rect is None:
            self._sup.blit(self._velo, (0, 0))
        else:
            self._sup.blit(self._velo, rect.topleft, rect)

    def _preparar(self, lamina) -> _Preparada:
        p = self._cache.get(id(lamina))
        # Se compara el objeto, no sólo el id: Python reusa los id de lo que ya se borró.
        if p is not None and p.lamina is lamina and p.fondo.get_size() == self._tam:
            return p

        # Sin deformar: una pantalla que no es 16:9 queda con franjas negras,
        # como hace VLC con --autoscale.
        w, h = self._tam
        e = min(w / lamina.fondo.width, h / lamina.fondo.height)
        ancho, alto = round(lamina.fondo.width * e), round(lamina.fondo.height * e)
        ox, oy = (w - ancho) // 2, (h - alto) // 2

        superficie = self._superficie

        def escalar(r):
            x, y, rw, rh = r
            return pygame.Rect(ox + round(x * e), oy + round(y * e), round(rw * e), round(rh * e))

        fondo = pygame.Surface((w, h)).convert()
        fondo.fill((0, 0, 0))
        fondo.blit(superficie(lamina.fondo, (ancho, alto), False), (ox, oy))
        zona = escalar(lamina.zona) if lamina.zona else None
        paginas = [superficie(img, zona.size, True) for img in lamina.paginas] if zona else []
        zona_av = escalar(lamina.zona_avance) if lamina.zona_avance else None
        avances = [superficie(img, zona_av.size, True) for img in lamina.avances] if zona_av else []
        zona_hora = escalar(lamina.zona_hora) if getattr(lamina, 'zona_hora', None) else None
        p = _Preparada(lamina, fondo, zona, paginas, zona_av, avances, zona_hora)
        self._cache[id(lamina)] = p
        return p

    @staticmethod
    def _superficie(img, tam, alfa):
        """Una imagen de Pillow como superficie de pygame, al tamaño `tam`."""
        from PIL import Image   # noqa: PLC0415 — viene con spui.tv
        if img.size != tuple(tam):
            img = img.resize(tuple(tam), Image.LANCZOS)
        img = img.convert('RGBA' if alfa else 'RGB')
        s = pygame.image.frombuffer(img.tobytes(), img.size, img.mode)
        return s.convert_alpha() if alfa else s.convert()

    def _olvidar_viejas(self, actual) -> None:
        """Se guardan las últimas láminas convertidas (pesan: ~8 MB cada una)."""
        if len(self._cache) > 4:
            for clave in list(self._cache)[:-4]:
                if clave != id(actual):
                    self._cache.pop(clave, None)
