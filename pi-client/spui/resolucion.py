"""
Resolución fija de la pantalla (03/11/2026).

Algunos TV no le pasan a la Pi la lista de resoluciones que aceptan (el EDID):
pasó con un TV de 42" que también le hacía lo mismo a otras computadoras. Sin
esa lista, el entorno gráfico arranca en 1024×768 aunque el TV sea Full HD, y
el contenido se ve chico, con franjas negras. Con `video=` en
/boot/firmware/cmdline.txt el kernel ya usa 1920×1080, pero X igual elige
1024×768 como "preferida" (docs/09_instalacion_raspberry.md §14).

SPUI_RESOLUCION=1920x1080 en spui.env hace que el cliente la imponga con
xrandr: al detectar la pantalla y cada vez que la vuelve a encender después
de un apagado por horario (que usaba --auto, o sea la "preferida"). Si el TV
no la informa, se agrega con el modo estándar (CEA-861) o el que calcule
`cvt`. Sin la variable, nada cambia.
"""

import re
from typing import Callable, Optional

# Modos estándar de TV (CEA-861): los que acepta cualquier TV de esa resolución.
_MODOS_ESTANDAR = {
    (1920, 1080): '148.50 1920 2008 2052 2200 1080 1084 1089 1125 +hsync +vsync',   # 60 Hz
    (1280, 720): '74.25 1280 1390 1430 1650 720 725 730 750 +hsync +vsync',        # 60 Hz
    (3840, 2160): '297.00 3840 4016 4104 4400 2160 2168 2178 2250 +hsync +vsync',  # 30 Hz
}


def leer(valor: Optional[str]) -> Optional[tuple[int, int]]:
    """'1920x1080' → (1920, 1080). Vacío o mal escrito → None (sin resolución fija)."""
    m = re.fullmatch(r'\s*(\d{3,4})\s*[xX×]\s*(\d{3,4})\s*', valor or '')
    return (int(m.group(1)), int(m.group(2))) if m else None


def modos(query: str, salida: str) -> tuple[list[str], Optional[str]]:
    """Los modos que xrandr lista para `salida` y el que está en uso (el del *)."""
    lista, actual, dentro = [], None, False
    for linea in query.splitlines():
        if not linea.startswith((' ', '\t')):
            dentro = linea.split(' ', 1)[0] == salida
            continue
        if dentro and linea.strip():
            nombre = linea.split()[0]
            lista.append(nombre)
            if '*' in linea:
                actual = nombre
    return lista, actual


def linea_de_modo(ancho: int, alto: int, cvt: Optional[Callable[[int, int], Optional[str]]] = None) -> Optional[str]:
    """Los tiempos del modo para `xrandr --newmode`: el estándar, o el que calcule cvt."""
    if (ancho, alto) in _MODOS_ESTANDAR:
        return _MODOS_ESTANDAR[(ancho, alto)]
    return cvt(ancho, alto) if cvt else None


def pasos(query: str, salida: str, ancho: int, alto: int,
          cvt: Optional[Callable[[int, int], Optional[str]]] = None) -> tuple[list[list[str]], Optional[str]]:
    """
    Los comandos xrandr (sin el 'xrandr') para dejar `salida` en ancho×alto.
    Devuelve ([], None) si ya está, (pasos, None) si hay que cambiarla, o
    ([], motivo) si no se puede.
    """
    lista, actual = modos(query, salida)
    buscado = f'{ancho}x{alto}'
    # El nombre puede venir con sufijo ("1920x1080i", "1920x1080_60"): se prefiere el exacto.
    propio = next((m for m in lista if m == buscado), None) or \
        next((m for m in lista if m.startswith(buscado + '_')), None)
    if propio and propio == actual:
        return [], None
    if propio:
        return [['--output', salida, '--mode', propio]], None
    tiempos = linea_de_modo(ancho, alto, cvt)
    if not tiempos:
        return [], f'el TV no informa {buscado} y no se pudo calcular ese modo (falta cvt)'
    nombre = f'{buscado}_spui'
    return [
        ['--newmode', nombre, *tiempos.split()],
        ['--addmode', salida, nombre],
        ['--output', salida, '--mode', nombre],
    ], None


def cvt_desde(salida_cvt: str) -> Optional[str]:
    """Los tiempos de la línea 'Modeline "…" 173.00 1920 …' que imprime cvt."""
    m = re.search(r'Modeline\s+"[^"]*"\s+(.+)', salida_cvt)
    return m.group(1).strip() if m else None
