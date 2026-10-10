"""
Acceso al servidor gráfico X11 de la Pi, compartido por el player (VLC) y la
gestión energética (xrandr).

El servicio systemd no corre dentro de la sesión gráfica: para hablarle a X
hay que encontrar el DISPLAY y la cookie de autorización de la sesión que
levantó startx.
"""

import os
from typing import Optional


def buscar_display() -> Optional[str]:
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


def buscar_xauthority() -> Optional[str]:
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


def entorno_x() -> Optional[dict]:
    """
    Variables de entorno para lanzar un programa de X (xrandr, xset) desde el
    servicio, o None si no hay servidor X corriendo.
    """
    display = buscar_display()
    if display is None:
        return None
    env = dict(os.environ, DISPLAY=display)
    cookie = buscar_xauthority()
    if cookie:
        env["XAUTHORITY"] = cookie
    return env
