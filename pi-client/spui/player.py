"""
Reproductor multimedia para SPUI Pi Client.

Tipos de contenido soportados (deben coincidir con SPUI\Enum\TipoContenido):
  texto      → texto mostrado con overlay VLC marquee
  imagen     → imagen estática (PNG, JPG) mostrada N segundos
  video      → archivo de video reproducido N segundos
  youtube    → URL de YouTube reproducida con VLC
  qr         → imagen PNG del QR (descargada del CMS)
  cronograma → tabla de actividades del día, renderizada como texto

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
import math
import os
import struct
import threading
import time
import wave
from typing import Optional

import config

logger = logging.getLogger(__name__)

# VLC es opcional — si no está instalado se usa modo simulación
try:
    import vlc  # python-vlc
    _VLC_IMPORTABLE = True
except ImportError:
    _VLC_IMPORTABLE = False


def _buscar_display() -> Optional[str]:
    """
    Devuelve el DISPLAY a usar, o None si no hay servidor X corriendo.

    Que python-vlc se pueda importar NO significa que haya una pantalla donde
    dibujar. Corriendo como servicio de systemd no hay sesión gráfica, y VLC
    aborta el proceso a nivel nativo ("Error opening terminal") — no es una
    excepción de Python, así que no se puede atrapar con try/except: hay que
    comprobarlo ANTES de inicializar VLC.

    Los sockets de X viven en /tmp/.X11-unix/X<n>; su presencia indica que hay
    un servidor escuchando en ese display.
    """
    display = os.environ.get('DISPLAY')
    if display:
        return display

    try:
        sockets = sorted(os.listdir('/tmp/.X11-unix'))
    except OSError:
        return None

    for nombre in sockets:
        if nombre.startswith('X') and nombre[1:].isdigit():
            return ':' + nombre[1:]

    return None


def _buscar_xauthority() -> Optional[str]:
    """
    Archivo de autorización de X (cookie), o None si no hace falta buscarlo.

    En Raspberry Pi OS, startx arranca Xorg con '-auth /tmp/serverauth.XXXXXX',
    con un nombre distinto en cada arranque. Sin esa cookie, un proceso de otra
    sesión —como el servicio systemd— se conecta al display y recibe
    "No protocol specified". Por eso se busca en tiempo de ejecución en vez de
    fijarla en el archivo del servicio.
    """
    if os.environ.get('XAUTHORITY'):
        return os.environ['XAUTHORITY']

    candidatos = []

    # El que esté usando el Xorg en curso (lo más confiable).
    try:
        for pid in os.listdir('/proc'):
            if not pid.isdigit():
                continue
            try:
                with open(f'/proc/{pid}/cmdline', 'rb') as fh:
                    args = fh.read().decode('utf-8', 'replace').split('\x00')
            except OSError:
                continue
            if args and args[0].endswith('Xorg') and '-auth' in args:
                candidatos.append(args[args.index('-auth') + 1])
    except OSError:
        pass

    # Ubicaciones habituales como respaldo.
    candidatos.append(os.path.expanduser('~/.Xauthority'))

    for ruta in candidatos:
        if ruta and os.path.exists(ruta):
            return ruta

    return None


def _detectar_video() -> tuple[bool, Optional[str], Optional[str]]:
    """
    Averigua si se puede dibujar en pantalla.

    Se llama al CREAR el Player, no al importar el módulo: los mensajes que se
    emiten durante el import se pierden, porque main.py todavía no configuró el
    logging. Por eso el arranque no decía ni "reproductor activo" ni "modo
    simulación", y el equipo quedaba en negro sin ninguna pista del motivo.

    Devuelve (puede_dibujar, display, motivo_si_no_puede).
    """
    if not _VLC_IMPORTABLE:
        return False, None, 'python-vlc no está instalado en la Raspberry Pi.'

    display = _buscar_display()
    if display is None:
        return False, None, (
            'No se encontró ningún servidor gráfico X11 (no hay socket en '
            '/tmp/.X11-unix ni la variable DISPLAY). VLC necesita uno para dibujar. '
            'Ver docs/09_instalacion_raspberry.md §8.'
        )

    os.environ['DISPLAY'] = display
    cookie = _buscar_xauthority()
    if cookie:
        os.environ['XAUTHORITY'] = cookie

    return True, display, None


class Player:
    """
    Reproductor thread-safe para items de playlist y alertas de emergencia.

    Las alertas tienen prioridad máxima: el método reproducir_playlist()
    detecta la flag y retorna anticipadamente para que main.py procese la alerta.
    """

    # Tiempo máximo de espera simulada en dev (evita tests lentos)
    _MAX_SIM_WAIT = 3

    # Módulos de salida de vídeo, del más confiable al menos, por si en algún
    # equipo el primero no dibuja. Se puede forzar uno con SPUI_VOUT en
    # /etc/spui/spui.env.
    #   xcb_x11 → salida X11 clásica, sin aceleración 3D (la más compatible)
    #   xcb_xv  → X11 con overlay de vídeo por hardware
    #   gl/glx  → OpenGL; más rápidas pero dependen de drivers 3D bien configurados
    _VOUT_ALTERNATIVAS = ('xcb_x11', 'xcb_xv', 'gl', 'glx')

    def __init__(self, media_dir: str, sync_client=None):
        self._media_dir = media_dir
        # Mismo cliente HTTP que ya descarga imágenes/video (sync.py): permite
        # bajar y cachear un sonido de alerta personalizado con la misma
        # verificación de hash. Puede llegar None (p.ej. uso directo del
        # Player en una prueba) — se resuelve igual con lo que haya en caché.
        self._sync_client = sync_client
        self._lock = threading.Lock()
        self._alerta: Optional[dict] = None
        self._stop = threading.Event()
        self._vlc: Optional['vlc.Instance'] = None
        self._media_player: Optional['vlc.MediaPlayer'] = None
        # Reproductor de audio DEDICADO a la alerta, separado del que dibuja
        # el texto/marquee: así el sonido no pisa ni es pisado por lo visual.
        self._audio_player: Optional['vlc.MediaPlayer'] = None
        # Se completa al reproducir, si el contenido no llega a pantalla completa.
        self.problema_pantalla: Optional[str] = None
        # Evita recargar la pantalla de espera en cada ciclo (haría parpadeo).
        self._mostrando_fallback = False
        # Qué playlist está puesta ahora, para no recargarla si no cambió.
        self._firma_actual: Optional[str] = None

        # La detección va acá y no en el import: durante el import todavía no
        # hay logging configurado y estos mensajes se perdían, dejando la
        # pantalla en negro sin explicación en el journal.
        puede_dibujar, display, motivo = _detectar_video()
        self.motivo_simulacion: Optional[str] = motivo
        self._simulacion = not puede_dibujar

        if not puede_dibujar:
            logger.warning('Modo SIMULACIÓN (sin salida de pantalla): %s', motivo)
            return

        logger.info('Inicializando VLC (DISPLAY=%s)…', display)

        # Salida de vídeo: se puede forzar con SPUI_VOUT si en algún equipo la
        # elegida no funciona. Ver _VOUT_ALTERNATIVAS para los valores válidos.
        self._vout = os.environ.get('SPUI_VOUT', 'xcb_x11').strip() or 'xcb_x11'

        try:
            # Sólo opciones seguras y bien soportadas. Nada que pueda impedir
            # que VLC cree su ventana de salida: acá estuvo el problema de que
            # el reproductor decía estar reproduciendo con la pantalla en negro
            # (--no-video-deco impide la salida de vídeo en varias builds).
            #
            # El marco de la ventana lo dibuja el gestor de ventanas, no VLC,
            # así que se resuelve en Openbox — ver
            # docs/09_instalacion_raspberry.md §8.5.
            self._vlc = vlc.Instance(
                # Módulo de salida de vídeo EXPLÍCITO. Sin esto VLC elige solo
                # entre los 21 que tiene instalados, y en la Pi 4 elegía uno
                # basado en OpenGL (gl / egl_x11) que crea la ventana pero no
                # llega a dibujar: quedaba una ventana de 1920x1080 en negro y
                # VLC informaba State.Playing igual, sin ningún error.
                #
                # xcb_x11 es la salida X11 clásica: no depende de aceleración
                # 3D y es la que anda en cualquier Pi con X corriendo.
                f'--vout={self._vout}',
                # Escalado automático al tamaño de la ventana (que es toda la
                # pantalla). Conserva la proporción original: el contenido
                # entra completo y sin recortes, y lo que sobra queda en negro.
                # Ojo con --no-autoscale: hace lo contrario, deja las imágenes
                # chicas en su tamaño real en medio de la pantalla.
                '--autoscale',
                # Nada de textos ni carátulas superpuestas.
                '--no-video-title-show',
                '--no-osd',
                # El puntero desaparece solo tras un segundo sin moverse.
                '--mouse-hide-timeout=1000',
                '--quiet',
            )
            if self._vlc is None:
                raise RuntimeError('vlc.Instance() devolvió None')

            self._media_player = self._vlc.media_player_new()

            # --fullscreen es una opción del REPRODUCTOR, no de la instancia:
            # pasarla a vlc.Instance() no hace nada (por eso el contenido se
            # veía en una ventana). Hay que pedirlo sobre el media player.
            self._media_player.set_fullscreen(True)
            try:
                self._media_player.video_set_mouse_input(False)
                self._media_player.video_set_key_input(False)
            except Exception:
                # Sólo evita que el contenido reaccione al mouse/teclado.
                # Si la build de VLC no lo soporta, no vale la pena abortar.
                pass

            logger.info('VLC listo (salida de vídeo: %s) — el contenido se va a mostrar en pantalla.', self._vout)
        except Exception as exc:
            # Hay display pero VLC no arrancó (falta un códec, el usuario del
            # servicio no tiene permiso sobre la pantalla, etc.). Se sigue en
            # simulación en vez de tumbar el reproductor: así el equipo queda
            # visible en el CMS con el motivo, en lugar de reiniciarse en bucle.
            self._vlc = None
            self._media_player = None
            self._simulacion = True
            self.motivo_simulacion = f'VLC no pudo inicializarse: {exc}'
            logger.error('VLC no pudo inicializarse (%s) — modo SIMULACIÓN.', exc)

    # ── API pública ────────────────────────────────────────────────────────

    # Duración que se aplica a un contenido sin duración propia cuando comparte
    # playlist con otros: en el CMS "vacío" significa permanente, pero dentro de
    # una rotación eso dejaría a los demás sin mostrarse nunca.
    _DURACION_POR_DEFECTO = 10

    # Un ítem permanente devuelve None, no un número: "permanente" es un estado,
    # no una cantidad de segundos. Antes acá había un tope de 300 s inventado por
    # el cliente, y el resultado era que una imagen configurada para verse
    # siempre se borraba a los 5 minutos y dejaba la pantalla en negro.
    PERMANENTE = None

    def reproducir_playlist(self, items: list[dict], sync_client=None) -> None:
        """
        Reproduce la playlist una vuelta.

        Un contenido permanente (único ítem sin duración) se carga una sola vez
        y se deja en pantalla: en los ciclos siguientes se detecta que ya está
        puesto y no se vuelve a cargar, para que no parpadee cada vez que el
        cliente sincroniza. Si el CMS cambia la programación, la firma cambia y
        recién ahí se reemplaza lo que se está mostrando.
        """
        if not items:
            self.mostrar_fallback()
            return

        firma = self._firma_playlist(items)

        # Único contenido permanente que ya está en pantalla: no tocar nada.
        if (
            len(items) == 1
            and self._resolver_duracion(items[0], 1) is None
            and firma == self._firma_actual
            and not self._mostrando_fallback
        ):
            return

        self._firma_actual = firma

        for item in items:
            if self._tiene_alerta() or self._stop.is_set():
                return

            duracion = self._resolver_duracion(item, len(items))
            self._reproducir_item(item, sync_client, duracion)

            # Permanente: ya quedó en pantalla y ahí se queda. No se espera ni
            # se borra; el ciclo de main.py decide cuándo reemplazarlo.
            if duracion is None:
                return

    @staticmethod
    def _firma_playlist(items: list[dict]) -> str:
        """
        Identifica el contenido de la playlist para saber si cambió entre syncs.

        Se usan id, hash del archivo y duración: si el operador reemplaza el
        archivo o cambia la duración desde el CMS, la firma cambia y el
        contenido se recarga aunque el id sea el mismo.
        """
        partes = []
        for item in items:
            c = item.get('contenido') or {}
            partes.append('{}:{}:{}'.format(
                c.get('id'),
                c.get('hash_archivo') or c.get('contenido_texto') or '',
                item.get('duracion_efectiva_seg'),
            ))
        return '|'.join(partes)

    def _resolver_duracion(self, item: dict, total_items: int) -> Optional[int]:
        """
        Segundos que debe durar un ítem, o None si es permanente.

        La fuente de verdad es el CMS: si allá se cargó como permanente, acá se
        muestra de forma permanente. El cliente no inventa topes.

        El CMS envía duracion_efectiva_seg = null cuando el contenido no tiene
        duración propia ni override, que en el panel significa "permanente".
        Ojo: la clave viaja presente con valor null, así que un
        item.get('clave', 10) NO cubre este caso — devuelve None y rompía toda
        la aritmética aguas abajo (era el TypeError que tiraba el reproductor).

        Criterio:
          - permanente + único contenido → se muestra siempre (None).
          - permanente + varios contenidos → duración por defecto, porque si no
            los demás no se verían nunca.
          - con valor → exactamente ese valor.
        """
        # Se distingue "el CMS mandó null" (permanente, intencional) de "la
        # clave no vino" (respuesta incompleta o de una versión vieja del
        # servidor). En el segundo caso no se asume permanente: dejaría un
        # contenido fijo para siempre por un error de datos.
        if 'duracion_efectiva_seg' not in item:
            logger.warning(
                'El servidor no informó la duración del contenido — se usan %ds.',
                self._DURACION_POR_DEFECTO,
            )
            return self._DURACION_POR_DEFECTO

        duracion = item.get('duracion_efectiva_seg')

        if duracion is None:
            return self.PERMANENTE if total_items == 1 else self._DURACION_POR_DEFECTO

        try:
            duracion = int(duracion)
        except (TypeError, ValueError):
            logger.warning('Duración inválida (%r) — se usan %ds.', duracion, self._DURACION_POR_DEFECTO)
            return self._DURACION_POR_DEFECTO

        # Una duración de 0 o negativa haría pasar el contenido de largo.
        return duracion if duracion > 0 else self._DURACION_POR_DEFECTO

    def mostrar_alerta(self, alerta: dict) -> None:
        """
        Override inmediato: muestra la alerta hasta que se desactive.

        Llega por dos vías con formatos distintos:
          - MQTT (push inmediato): titulo, mensaje, prioridad, expira_en.
            NO incluye el contenido multimedia adjunto.
          - REST (respuesta del sync): además trae 'contenido' con la media.

        Por eso sólo se usan los campos comunes a ambas. Si la alerta tenía una
        imagen o un video adjunto, se ve recién cuando el próximo sync la traiga
        por REST.

        Si algo falla acá, hay que limpiar self._alerta: el estado se marca al
        entrar, y quedarse marcado sin nada en pantalla dejaba al reproductor
        convencido de estar mostrando una alerta — no volvía a la playlist ni
        bajaba el brillo, en silencio y sin error visible.
        """
        if not isinstance(alerta, dict):
            logger.error('Alerta con formato inesperado (%s) — se ignora.', type(alerta).__name__)
            return

        with self._lock:
            self._alerta = alerta

        # La alerta tapa la playlist, así que al desactivarse hay que volver a
        # cargar el contenido: se invalida la firma para forzar la recarga.
        self._firma_actual = None
        self._mostrando_fallback = False

        try:
            titulo  = alerta.get('titulo') or 'ALERTA DE EMERGENCIA'
            mensaje = alerta.get('mensaje') or ''
            logger.warning('ALERTA: %s', titulo)

            self._reproducir_texto(f'{titulo}\n\n{mensaje}', duracion=None, es_alerta=True)
        except Exception:
            logger.exception('Falló al mostrar la alerta — se vuelve a la programación normal.')
            with self._lock:
                self._alerta = None
            self._parar_vlc()
            self._detener_sonido()

    def desactivar_alerta(self) -> None:
        with self._lock:
            self._alerta = None
        logger.info('Alerta desactivada — volviendo a programación normal.')
        self._parar_vlc()
        # Se corta acá mismo (no alcanza con que _esperar_alerta() lo note en
        # su próxima vuelta, hasta 0.5s después): un sonido de alarma que
        # sigue sonando un rato después de desactivada es exactamente el bug
        # que no se quiere.
        self._detener_sonido()
        # La pantalla quedó vacía: hay que volver a cargar la playlist en el
        # próximo ciclo, aunque la programación no haya cambiado.
        self._firma_actual       = None
        self._mostrando_fallback = False

    # Texto institucional que se muestra cuando no hay nada programado.
    _TEXTO_FALLBACK = 'UNRaf\nSistema de Pantallas Informativas'

    def mostrar_fallback(self, duracion: Optional[int] = None) -> None:
        """
        Pantalla de espera cuando no hay programación activa ni playlist de
        respaldo configurada.

        Por defecto es permanente: queda en pantalla hasta que haya contenido
        real que mostrar. Una pantalla en negro en un pasillo parece un equipo
        roto, así que siempre tiene que haber algo.

        Se prefiere una imagen antes que el texto por overlay: el marquee de VLC
        depende de que haya un medio de vídeo debajo y es bastante más frágil.
        Si no hay imagen en assets/, se genera una la primera vez.
        """
        # Si ya se está mostrando el fallback, no se vuelve a cargar: recargarlo
        # en cada ciclo haría parpadear la pantalla.
        if self._mostrando_fallback:
            return

        logo = self._imagen_fallback()

        if not self._simulacion and logo:
            logger.info('[FALLBACK] Sin programación — se muestra la pantalla de espera.')
            self._reproducir_imagen(logo, duracion)
            self._mostrando_fallback = True
            return

        logger.info('[FALLBACK] Sin programación (texto).')
        self._reproducir_texto(self._TEXTO_FALLBACK, duracion)
        self._mostrando_fallback = True

    def _imagen_fallback(self) -> Optional[str]:
        """
        Ruta de la imagen de espera. Si no existe, intenta generarla una vez.

        La generación usa Pillow si está disponible; si no, se cae al texto.
        Se guarda en el directorio de medios (escribible por el servicio), no
        junto al código, que puede ser de sólo lectura.
        """
        propia = os.path.join(os.path.dirname(__file__), '..', 'assets', 'logo_fallback.png')
        if os.path.exists(propia):
            return propia

        generada = os.path.join(self._media_dir, '_fallback.png')
        if os.path.exists(generada):
            return generada

        try:
            from PIL import Image, ImageDraw   # noqa: PLC0415 — opcional, sólo acá

            img  = Image.new('RGB', (1920, 1080), (11, 61, 145))   # azul institucional
            draw = ImageDraw.Draw(img)
            texto = self._TEXTO_FALLBACK
            # Sin fuente propia se usa la de PIL: alcanza para un cartel de espera.
            caja = draw.multiline_textbbox((0, 0), texto, align='center')
            draw.multiline_text(
                ((1920 - (caja[2] - caja[0])) / 2, (1080 - (caja[3] - caja[1])) / 2),
                texto, fill=(255, 255, 255), align='center',
            )
            os.makedirs(self._media_dir, exist_ok=True)
            img.save(generada)
            logger.info('Imagen de espera generada en %s', generada)
            return generada
        except Exception as exc:
            logger.warning('No se pudo generar la imagen de espera (%s) — se usará texto.', exc)
            return None

    def reintentar_pantalla(self) -> bool:
        """
        Vuelve a intentar tomar la pantalla si se arrancó sin entorno gráfico.

        El servicio puede iniciar antes de que X11 termine de levantar. Sin
        esto, el reproductor quedaba en modo simulación hasta que alguien lo
        reiniciara a mano: arrancaba, no encontraba pantalla y se rendía para
        siempre. Se llama desde el ciclo principal, así que se recupera solo en
        cuanto X aparece.

        Devuelve True si a partir de ahora puede dibujar.
        """
        if not self._simulacion or not _VLC_IMPORTABLE:
            return not self._simulacion

        display = _buscar_display()
        if display is None:
            return False

        os.environ['DISPLAY'] = display
        cookie = _buscar_xauthority()
        if cookie:
            os.environ['XAUTHORITY'] = cookie

        try:
            self._vlc = vlc.Instance(
                '--autoscale', '--no-video-title-show', '--no-osd',
                '--mouse-hide-timeout=1000', '--quiet',
            )
            if self._vlc is None:
                raise RuntimeError('vlc.Instance() devolvió None')
            self._media_player = self._vlc.media_player_new()
            self._media_player.set_fullscreen(True)
        except Exception as exc:
            logger.warning('Reintento de pantalla fallido: %s', exc)
            return False

        self._simulacion         = False
        self.motivo_simulacion   = None
        self._firma_actual       = None     # forzar recarga: hay que dibujar todo de nuevo
        self._mostrando_fallback = False
        logger.info('Entorno gráfico disponible (DISPLAY=%s) — reproductor activo.', display)
        return True

    def esta_mostrando_alerta(self) -> bool:
        with self._lock:
            return self._alerta is not None

    def detener(self) -> None:
        self._stop.set()
        self._parar_vlc()
        self._detener_sonido()

    # ── Reproducción por tipo ──────────────────────────────────────────────

    def _reproducir_item(self, item: dict, sync_client, duracion: int) -> None:
        """
        Reproduce un ítem.

        `duracion` ya viene resuelta por _resolver_duracion: o un entero
        positivo de segundos, o None si el contenido es permanente.
        """
        self._mostrando_fallback = False

        contenido = item.get('contenido') or {}
        tipo      = contenido.get('tipo') or ''

        # %s y no %d: con un contenido permanente la duración es None, y %d
        # revienta el formateo del log (lo que tiraba el proceso entero).
        logger.info(
            'Reproduciendo [%s] "%s" (%s)',
            tipo,
            contenido.get('titulo') or '',
            'permanente' if duracion is None else f'{duracion}s',
        )

        if tipo == 'texto':
            # contenido_texto es nullable en el CMS: un texto sin cuerpo llega
            # como null, y el default de .get() no lo cubre.
            self._reproducir_texto(contenido.get('contenido_texto') or '', duracion)

        elif tipo in ('imagen', 'video', 'qr'):
            # url_descarga es la URL completa: http://servidor/api/spui/media/filename.ext
            # Es nullable: un contenido sin archivo cargado la manda en null.
            url_descarga = contenido.get('url_descarga') or ''
            local = self._resolver_local(url_descarga, contenido, sync_client)
            if local:
                if tipo == 'video':
                    self._reproducir_video(local, duracion)
                else:
                    self._reproducir_imagen(local, duracion)
            else:
                # El archivo no está en el caché y no se pudo descargar. No se
                # espera indefinidamente por algo que no se puede mostrar: se
                # deja la pantalla de espera y se reintenta en el próximo ciclo,
                # cuando el prefetch quizá ya lo haya traído.
                logger.warning(
                    'Archivo no disponible: %s — se muestra la pantalla de espera.',
                    url_descarga or '(sin archivo)',
                )
                self.problema_pantalla = (
                    f'No se pudo obtener el archivo de "{contenido.get("titulo") or "un contenido"}". '
                    'Se está mostrando la pantalla de espera.'
                )
                self._mostrando_fallback = False   # forzar que se dibuje
                self.mostrar_fallback()
                # La firma se invalida para reintentar el contenido real luego.
                self._firma_actual = None

        elif tipo == 'youtube':
            self._reproducir_youtube(contenido.get('contenido_texto') or '', duracion)

        elif tipo == 'cronograma':
            # El CMS ya filtró los items por el día de hoy y los ordenó por hora
            # (ver SyncController::serializeItem). Acá sólo se formatean.
            self._reproducir_cronograma(
                contenido.get('titulo', ''),
                contenido.get('cronograma_items') or [],
                duracion,
            )

        else:
            logger.warning('Tipo desconocido: %s', tipo)
            self._esperar(duracion)

    def _reproducir_cronograma(self, titulo: str, items: list[dict], duracion: Optional[int]) -> None:
        """
        Renderiza un cronograma como texto tabulado.

        items llega ya filtrado por día y ordenado por hora desde el CMS; cada
        entrada tiene nombre, aula, hora_inicio y hora_fin. Una lista vacía
        significa que hoy no hay actividades: se informa en vez de dejar la
        pantalla en blanco.
        """
        if not items:
            self._reproducir_texto(
                f'{titulo}\n\nSin actividades programadas para hoy.',
                duracion,
            )
            return

        lineas = [titulo, '']
        for it in items:
            horario = f"{it.get('hora_inicio', '')}-{it.get('hora_fin', '')}"
            aula    = it.get('aula') or ''
            nombre  = it.get('nombre', '')
            lineas.append(f"{horario}  {nombre}" + (f"  ({aula})" if aula else ''))

        self._reproducir_texto('\n'.join(lineas), duracion)

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

    def _reproducir_media(self, media, es_imagen_fija: bool = False) -> None:
        """
        Pone un medio en pantalla completa y arranca la reproducción.

        El fullscreen se vuelve a pedir en CADA medio a propósito: VLC lo
        asocia a la ventana de salida de vídeo, y esa ventana se recrea al
        cambiar de contenido, así que el estado no siempre sobrevive. Pedirlo
        una sola vez al inicializar alcanzaba para el primer archivo y después
        el resto salía en ventana.

        `es_imagen_fija` cambia cómo se juzga el estado State.Ended: en una
        imagen es normal y el fotograma queda visible; en un vídeo significa
        que se cortó. Ver el detalle en la comprobación de estado, más abajo.
        """
        self._media_player.set_media(media)

        # play() devuelve -1 si no pudo arrancar. Ignorar ese valor era la razón
        # de que el log dijera "Reproduciendo" con la pantalla en negro.
        if self._media_player.play() == -1:
            self.problema_pantalla = (
                'VLC no pudo iniciar la reproducción. Revisá que la Raspberry tenga '
                'entorno gráfico y que el archivo sea reproducible.'
            )
            logger.error('%s', self.problema_pantalla)
            return

        try:
            self._media_player.set_fullscreen(True)
        except Exception as exc:
            logger.warning('No se pudo forzar pantalla completa: %s', exc)

        # Confirmar que efectivamente está dibujando. VLC tarda un instante en
        # abrir la salida de vídeo, así que se le da un margen antes de juzgar.
        estado_ok = False
        for _ in range(20):                      # hasta ~2 segundos
            if self._stop.is_set():
                return
            estado = self._media_player.get_state()
            if estado == vlc.State.Playing:
                estado_ok = True
                break
            if estado == vlc.State.Error:
                break
            if estado == vlc.State.Ended:
                # Con una imagen fija, VLC llega a Ended en cuanto termina de
                # decodificarla: no hay flujo que seguir reproduciendo. El
                # fotograma QUEDA en pantalla, así que esto no es un fallo.
                #
                # Tratarlo como error dejaba la pantalla sin contenido y
                # reportaba "puede estar en negro" cuando la imagen se veía
                # perfecto. Aparecía sobre todo al reiniciar el reproductor con
                # una única imagen permanente programada, porque ahí VLC llega a
                # Ended antes de que este bucle alcance a ver Playing.
                estado_ok = es_imagen_fija
                break
            time.sleep(0.1)

        if not estado_ok:
            self.problema_pantalla = (
                f'El contenido no llegó a mostrarse en pantalla (estado de VLC: '
                f'{self._media_player.get_state()}). La pantalla puede estar en negro.'
            )
            logger.error('%s', self.problema_pantalla)
            return

        # Reproduce bien: se comprueba el fullscreen. Si el gestor de ventanas
        # ignora el pedido, el contenido se ve enmarcado — algo que sólo se nota
        # mirando la pantalla en persona, así que se informa al panel.
        try:
            if not self._media_player.get_fullscreen():
                self.problema_pantalla = (
                    'El contenido se está viendo en una ventana en lugar de ocupar toda la '
                    'pantalla. Falta configurar Openbox para que no le ponga marco a VLC '
                    '(ver docs/09_instalacion_raspberry.md §8.5).'
                )
                logger.warning('%s', self.problema_pantalla)
            else:
                self.problema_pantalla = None
        except Exception as exc:
            logger.warning('No se pudo verificar el estado de pantalla completa: %s', exc)

    def _reproducir_imagen(self, path: str, duracion: Optional[int]) -> None:
        """duracion=None → la imagen queda en pantalla hasta que algo la reemplace."""
        if self._simulacion:
            logger.info('[SIM] imagen: %s%s', os.path.basename(path),
                        ' (permanente)' if duracion is None else f' ({duracion}s)')
            self._esperar_simulado(duracion)
            return

        media = self._vlc.media_new(path)
        # -1 = VLC deja la imagen indefinidamente; el tiempo lo controlamos
        # nosotros con _esperar(), no VLC.
        media.add_option(':image-duration=-1')
        self._reproducir_media(media, es_imagen_fija=True)

        # Sin duración no hay nada que esperar: la imagen ya está en pantalla y
        # ahí se queda. Tampoco se llama a _parar_vlc(): borrar el medio dejaría
        # la pantalla en negro, que es justo lo que hay que evitar.
        if duracion is not None:
            self._esperar(duracion)

    def _reproducir_video(self, path: str, duracion: Optional[int]) -> None:
        if self._simulacion:
            logger.info('[SIM] video: %s', os.path.basename(path))
            self._esperar_simulado(duracion)
            return

        media = self._vlc.media_new(path)
        # Un video permanente se repite en bucle en vez de quedarse en el último
        # fotograma congelado.
        if duracion is None:
            media.add_option(':input-repeat=65535')

        self._reproducir_media(media)

        if duracion is not None:
            self._esperar(duracion)

    def _reproducir_texto(self, texto: Optional[str], duracion: Optional[int], es_alerta: bool = False) -> None:
        """
        Muestra texto en pantalla.

        duracion=None significa "sin límite", y se resuelve distinto según el caso:
          - alerta: se queda hasta que la desactiven (bucle vigilando el estado).
          - contenido permanente: se deja en pantalla y se vuelve enseguida, para
            no bloquear el ciclo de sync.
        """
        texto = texto or ''
        label = 'ALERTA' if es_alerta else 'TEXTO'

        if self._simulacion:
            logger.info('[SIM] %s: %s', label, texto[:120])
            if es_alerta and duracion is None:
                self._esperar_alerta()
            else:
                self._esperar_simulado(duracion)
            return

        media = self._vlc.media_new('blank://')
        media.add_option(':sub-filter=marq')
        media.add_option(f':marq-marquee={texto}')
        media.add_option(':marq-position=8')   # centro
        media.add_option(':marq-size=48')
        if es_alerta:
            media.add_option(':marq-color=0xFF0000')

        self._reproducir_media(media)

        if duracion is not None:
            self._esperar(duracion)
        elif es_alerta:
            # La alerta sí bloquea: tiene que verse hasta que la den de baja.
            self._esperar_alerta()
            self._parar_vlc()   # al desactivarse, se limpia para volver a la playlist
        # Texto permanente: queda en pantalla, sin esperar ni borrar.

    def _reproducir_youtube(self, url: str, duracion: Optional[int]) -> None:
        if self._simulacion:
            logger.info('[SIM] youtube: %s', url)
            self._esperar_simulado(duracion)
            return

        media = self._vlc.media_new(url)
        if duracion is None:
            media.add_option(':input-repeat=65535')

        self._reproducir_media(media)

        if duracion is not None:
            self._esperar(duracion)

    # ── Utilidades ────────────────────────────────────────────────────────

    def _esperar(self, segundos: Optional[float]) -> None:
        """
        Espera N segundos en incrementos de 0.5s revisando alertas y stop.

        Acepta None como red de seguridad: la duración ya viene normalizada
        desde _resolver_duracion, pero éste es el punto donde un None terminaba
        matando el proceso ('float + NoneType'), así que no se vuelve a confiar
        en que el llamador siempre haga lo correcto.
        """
        if segundos is None:
            segundos = self._DURACION_POR_DEFECTO

        deadline = time.monotonic() + segundos
        while time.monotonic() < deadline:
            if self._tiene_alerta() or self._stop.is_set():
                return
            time.sleep(0.5)

    def _esperar_alerta(self) -> None:
        """
        Mantiene la alerta en pantalla hasta que se desactive o se apague el
        cliente, y repite el sonido cada SPUI_ALERTA_SONIDO_INTERVALO_SEG
        mientras tanto.

        El sonido va DENTRO de este mismo bucle a propósito, y no en un hilo
        o timer aparte: así queda estructuralmente atado a la misma condición
        (self._tiene_alerta()) que decide si la alerta sigue viva. Un timer
        separado podría sobrevivir a la desactivación si algo fallaba al
        cancelarlo — acá no hay nada que cancelar, en cuanto el bucle termina
        no hay más repeticiones posibles.

        El archivo a reproducir se resuelve una sola vez (puede implicar una
        descarga) y se reutiliza en cada repetición, no se vuelve a resolver
        cada vez.
        """
        ruta_sonido: Optional[str] = None
        sonido_resuelto = False
        ultimo_sonido = 0.0   # 0 fuerza la primera reproducción en la primera vuelta

        while self._tiene_alerta() and not self._stop.is_set():
            ahora = time.monotonic()
            if config.ALERTA_SONIDO_ACTIVO and (ahora - ultimo_sonido) >= config.ALERTA_SONIDO_INTERVALO_SEG:
                if not sonido_resuelto:
                    alerta_actual   = self._alerta_actual()
                    ruta_sonido     = self._resolver_sonido(alerta_actual) if alerta_actual else None
                    sonido_resuelto = True
                self._reproducir_sonido(ruta_sonido)
                ultimo_sonido = ahora
            time.sleep(0.5)

    def _esperar_simulado(self, duracion: Optional[int]) -> None:
        """
        Espera acotada para el modo simulación (sin pantalla).

        Un contenido permanente no puede bloquear el hilo para siempre cuando no
        hay nada que mostrar: se espera un instante y se sigue, así el ciclo de
        sync continúa y el equipo mantiene su heartbeat. Además evita el
        min(None, 3), que reventaba con los contenidos permanentes.
        """
        if duracion is None:
            self._esperar(self._MAX_SIM_WAIT)
        else:
            self._esperar(min(duracion, self._MAX_SIM_WAIT))

    def _tiene_alerta(self) -> bool:
        with self._lock:
            return self._alerta is not None

    def _alerta_actual(self) -> Optional[dict]:
        with self._lock:
            return self._alerta

    def _parar_vlc(self) -> None:
        if not self._simulacion and self._media_player:
            try:
                self._media_player.stop()
            except Exception:
                pass

    # ── Sonido de alerta ─────────────────────────────────────────────────

    _TONO_ALERTA_NOMBRE = '_alerta_tono_default.wav'

    def _resolver_sonido(self, alerta: dict) -> Optional[str]:
        """
        Ruta local del sonido a reproducir para esta alerta: el personalizado
        del CMS si se puede conseguir, si no el tono generado localmente.

        Nunca devuelve None salvo que ni siquiera se pueda generar el tono por
        defecto (por ejemplo, sin espacio en disco) — es la única forma de que
        una alerta quede sin sonido cuando se espera escuchar algo.
        """
        url = alerta.get('sonido_url')

        if url:
            if self._sync_client is not None:
                try:
                    return self._sync_client.descargar_media(url, alerta.get('sonido_hash'))
                except Exception as exc:
                    logger.warning(
                        'No se pudo obtener el sonido de alerta personalizado (%s) — se usa el tono por defecto.',
                        exc,
                    )
            else:
                # Sin cliente de sync (uso directo del Player) se prueba el
                # caché local por si ya se había descargado en otra corrida.
                nombre = url.rstrip('/').split('/')[-1]
                local  = os.path.join(self._media_dir, nombre)
                if os.path.exists(local):
                    return local
                logger.warning('Sonido de alerta personalizado no cacheado y sin cliente de sync — se usa el tono por defecto.')

        return self._tono_alerta_default()

    def _tono_alerta_default(self) -> Optional[str]:
        """
        Tono de alerta genérico: dos frecuencias tipo "beep-beep", ~0.9s.

        Se genera una sola vez con el módulo estándar 'wave' (sin bundlear
        ningún binario ni depender de red) y se cachea en media_dir. Es lo que
        garantiza que el reproductor JAMÁS quede mudo en una emergencia,
        incluso sin sonido personalizado configurado y sin conexión al CMS.
        """
        ruta = os.path.join(self._media_dir, self._TONO_ALERTA_NOMBRE)
        if os.path.exists(ruta):
            return ruta

        try:
            os.makedirs(self._media_dir, exist_ok=True)
            framerate = 22050
            with wave.open(ruta, 'wb') as wf:
                wf.setnchannels(1)
                wf.setsampwidth(2)
                wf.setframerate(framerate)
                # (frecuencia Hz, duración s); frecuencia 0 = silencio (pausa entre beeps).
                for frecuencia, duracion in ((880, 0.35), (0, 0.12), (660, 0.35)):
                    n_muestras = int(framerate * duracion)
                    for i in range(n_muestras):
                        if frecuencia == 0:
                            muestra = 0
                        else:
                            muestra = int(32767 * 0.5 * math.sin(2 * math.pi * frecuencia * i / framerate))
                        wf.writeframesraw(struct.pack('<h', muestra))
            logger.info('Tono de alerta por defecto generado en %s', ruta)
            return ruta
        except Exception as exc:
            logger.error('No se pudo generar el tono de alerta por defecto (%s) — la alerta quedará sin sonido.', exc)
            return None

    def _sonido_player(self) -> Optional['vlc.MediaPlayer']:
        """
        Reproductor de audio dedicado a la alerta (una sola vez, reutilizado
        en cada repetición). Separado del que dibuja el texto para no
        interferir con el marquee.
        """
        if self._simulacion or self._vlc is None:
            return None
        if self._audio_player is None:
            try:
                self._audio_player = self._vlc.media_player_new()
            except Exception as exc:
                logger.warning('No se pudo crear el reproductor de audio de alerta: %s', exc)
                return None
        return self._audio_player

    def _reproducir_sonido(self, ruta: Optional[str]) -> None:
        if not ruta or not config.ALERTA_SONIDO_ACTIVO:
            return

        if self._simulacion:
            logger.info('[SIM] sonido de alerta: %s', os.path.basename(ruta))
            return

        reproductor = self._sonido_player()
        if reproductor is None:
            return

        try:
            media = self._vlc.media_new(ruta)
            reproductor.set_media(media)
            reproductor.audio_set_volume(config.ALERTA_SONIDO_VOLUMEN)
            reproductor.play()
        except Exception as exc:
            logger.warning('No se pudo reproducir el sonido de alerta: %s', exc)

    def _detener_sonido(self) -> None:
        if self._audio_player is not None:
            try:
                self._audio_player.stop()
            except Exception:
                pass
