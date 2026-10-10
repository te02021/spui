"""
Brillo real del monitor por DDC/CI (ddcutil), el mismo canal que usa Windows
para el brillo de un monitor externo.

DDC/CI viaja por el bus I2C del cable HDMI. En I2C sólo la Pi inicia una
comunicación: el monitor contesta, pero nunca avisa por su cuenta. Por eso un
cambio hecho con los botones del monitor sólo se puede conocer leyendo.

Sólo se usan dos códigos VCP del estándar MCCS:
  0x10  Brillo (valor actual y máximo del monitor)
  0x02  "New Control Value": el monitor lo pone en 0x02 cuando alguien cambió
        un control desde su menú; se baja escribiendo 0x01. No todos los
        monitores lo implementan.

Las funciones no guardan estado: lo decide GestorEnergia (energia.py).
"""

import logging
import re
from dataclasses import dataclass
from typing import Callable, Optional

logger = logging.getLogger(__name__)

VCP_BRILLO = '10'
VCP_CAMBIO = '02'

Ejecutar = Callable[..., tuple[int, str]]


@dataclass(frozen=True)
class MonitorDdc:
    bus: int                 # /dev/i2c-<bus>
    identidad: str           # fabricante + modelo + número de serie del EDID


def detectar(ejecutar: Ejecutar) -> tuple[Optional[MonitorDdc], Optional[str]]:
    """
    Busca un monitor que responda a DDC/CI.

    Devuelve (monitor, None) si hay uno, o (None, motivo) si no: el motivo
    distingue "la pantalla no tiene DDC/CI" (lo normal en un TV) de un problema
    que se puede arreglar en la Pi (falta permiso sobre /dev/i2c-*).
    """
    codigo, salida = ejecutar(['ddcutil', 'detect'], None, 20)
    if codigo != 0 and 'Display' not in salida:
        if re.search(r'permission denied|EACCES', salida, re.IGNORECASE):
            return None, ('sin permiso sobre /dev/i2c-*: el usuario del servicio tiene que estar en el grupo i2c '
                          '(sudo usermod -aG i2c <usuario> y reiniciar)')
        if re.search(r'no /dev/i2c|i2c-dev', salida, re.IGNORECASE):
            return None, 'falta el módulo i2c-dev (sudo modprobe i2c-dev; install.sh lo deja cargado al arrancar)'
        if re.search(r'invalid display|no displays found|ddc communication failed', salida, re.IGNORECASE):
            return None, 'la pantalla no responde a DDC/CI'
        logger.warning('ddcutil detect terminó con código %s: %s', codigo, salida.strip()[-400:])
        return None, 'no se pudo consultar la pantalla por DDC/CI (detalle en el registro de la Pi)'

    # Sólo cuenta un "Display N" (los que no responden figuran como "Invalid display").
    bloque = re.search(r'^Display \d+\n(.*?)(?=^\S|\Z)', salida, re.MULTILINE | re.DOTALL)
    if not bloque:
        return None, 'la pantalla no responde a DDC/CI'
    texto = bloque.group(1)
    bus = re.search(r'/dev/i2c-(\d+)', texto)
    if not bus:
        return None, 'ddcutil no informó el bus I2C del monitor'

    partes = [re.search(p, texto) for p in (r'Mfg id:\s*(\S+)', r'Model:\s*(.+)', r'Binary serial number:\s*(\d+)')]
    identidad = ' / '.join(m.group(1).strip() for m in partes if m) or f'bus {bus.group(1)}'
    return MonitorDdc(int(bus.group(1)), identidad), None


def leer_brillo(ejecutar: Ejecutar, monitor: MonitorDdc) -> tuple[Optional[tuple[int, int]], Optional[str]]:
    """(valor actual, máximo) del brillo, o (None, motivo)."""
    codigo, salida = ejecutar(['ddcutil', '--bus', str(monitor.bus), '--brief', 'getvcp', VCP_BRILLO], None, 10)
    # Formato --brief: "VCP 10 C 67 100"
    m = re.search(r'VCP\s+10\s+C\s+(\d+)\s+(\d+)', salida)
    if codigo != 0 or not m:
        logger.warning('ddcutil getvcp 10 terminó con código %s: %s', codigo, salida.strip()[-400:])
        return None, 'no se pudo leer el brillo del monitor (detalle en el registro de la Pi)'
    actual, maximo = int(m.group(1)), int(m.group(2))
    if maximo <= 0:
        return None, f'el monitor informa un brillo máximo inválido ({maximo})'
    return (actual, maximo), None


def escribir_brillo(ejecutar: Ejecutar, monitor: MonitorDdc, valor: int) -> Optional[str]:
    """Pone el brillo en `valor` (unidades del monitor). None si salió bien."""
    codigo, salida = ejecutar(['ddcutil', '--bus', str(monitor.bus), 'setvcp', VCP_BRILLO, str(valor)], None, 10)
    if codigo != 0:
        logger.warning('ddcutil setvcp 10 %s terminó con código %s: %s', valor, codigo, salida.strip()[-400:])
        return 'el monitor no aceptó el cambio de brillo (detalle en el registro de la Pi)'
    return None


def hubo_cambio_manual(ejecutar: Ejecutar, monitor: MonitorDdc) -> Optional[bool]:
    """
    Lee el aviso "New Control Value" (VCP 0x02). True si el monitor indica que
    alguien tocó un control desde su menú, False si no, None si el monitor no
    implementa ese código.
    """
    codigo, salida = ejecutar(['ddcutil', '--bus', str(monitor.bus), '--brief', 'getvcp', VCP_CAMBIO], None, 10)
    m = re.search(r'VCP\s+02\s+\w+\s+(?:x)?([0-9a-fA-F]+)', salida)
    if codigo != 0 or not m:
        return None
    return int(m.group(1), 16) == 0x02


def bajar_aviso_de_cambio(ejecutar: Ejecutar, monitor: MonitorDdc) -> None:
    """Le dice al monitor "ya lo vi" (0x02 ← 0x01), para enterarse del próximo cambio."""
    ejecutar(['ddcutil', '--bus', str(monitor.bus), 'setvcp', VCP_CAMBIO, '1'], None, 10)
