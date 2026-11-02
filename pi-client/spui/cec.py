"""
HDMI-CEC sin consultar (noviembre 2026): un cec-client que queda corriendo,
escucha lo que avisa el TV y es por donde salen las órdenes de encender y
apagar.

Para qué: si alguien apaga el TV con el control en pleno horario, la Pi lo
deja apagado hasta el próximo encendido del horario (decisión de Mateo,
02/11/2026). Eso sólo se puede saber si el TV lo avisa por CEC; acá no se le
pregunta nada al TV, se escucha (Mateo descartó cualquier sondeo). Un TV sin
CEC, o que no avisa, no se entera de nada y todo sigue como antes.

Un solo proceso para escuchar y para mandar: dos cec-client a la vez se
disputan el adaptador. Mientras éste corre, spui/energia.py manda por acá en
vez de lanzar un `cec-client -s` por orden.

Qué se toma de lo que pasa por el cable (sólo lo que manda el TV, dirección 0):
  - 36 (Standby)                       → el TV se apagó
  - 90 (Report Power Status) 01 o 03   → el TV está apagado o apagándose
  - 90 00 o 02                         → el TV está encendido o encendiéndose
  - 84 / 87 a todos (dirección física, fabricante): lo anuncian al encenderse
"""

import logging
import re
import subprocess
import threading
from typing import Callable, Optional

logger = logging.getLogger(__name__)

# ">> 0f:36": un mensaje recibido (">>") en el registro de tráfico de cec-client.
_RECIBIDO = re.compile(r'>>\s*([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2})*)')


def interpretar(linea: str) -> Optional[str]:
    """'on', 'standby' o None según lo que dice una línea de tráfico de cec-client."""
    m = _RECIBIDO.search(linea)
    if not m:
        return None
    datos = [int(b, 16) for b in m.group(1).split(':')]
    if len(datos) < 2 or datos[0] >> 4 != 0:   # sólo lo que manda el TV
        return None
    op = datos[1]
    if op == 0x36:
        return 'standby'
    if op == 0x90 and len(datos) >= 3:
        return 'standby' if datos[2] in (0x01, 0x03) else 'on'
    if op in (0x84, 0x87) and datos[0] & 0x0F == 0x0F:
        return 'on'
    return None


class ClienteCec:
    def __init__(self, al_avisar: Callable[[str], None],
                 lanzar: Callable[..., subprocess.Popen] = subprocess.Popen):
        self._al_avisar = al_avisar
        self._lanzar = lanzar
        self._proceso: Optional[subprocess.Popen] = None
        self._lock = threading.Lock()

    def iniciar(self) -> bool:
        """Arranca cec-client como reproductor ("SPUI"), con el registro de tráfico."""
        try:
            self._proceso = self._lanzar(
                ['cec-client', '-t', 'p', '-o', 'SPUI', '-d', '9'],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                text=True, encoding='utf-8', errors='replace', bufsize=1,
            )
        except OSError as exc:
            logger.warning('No se pudo dejar escuchando HDMI-CEC: %s', exc)
            self._proceso = None
            return False
        threading.Thread(target=self._leer, daemon=True, name='cec').start()
        logger.info('Escuchando los avisos del TV por HDMI-CEC.')
        return True

    def vivo(self) -> bool:
        return self._proceso is not None and self._proceso.poll() is None

    def enviar(self, orden: str) -> bool:
        """Una orden de cec-client ('on 0', 'standby 0'). False si el proceso ya no está."""
        with self._lock:
            if not self.vivo():
                return False
            try:
                self._proceso.stdin.write(orden + '\n')
                self._proceso.stdin.flush()
                return True
            except (OSError, ValueError):
                return False

    def detener(self) -> None:
        proceso, self._proceso = self._proceso, None
        if proceso is None:
            return
        try:
            proceso.stdin.write('q\n')
            proceso.stdin.flush()
            proceso.wait(timeout=5)
        except Exception:
            proceso.kill()

    def _leer(self) -> None:
        proceso = self._proceso
        try:
            for linea in proceso.stdout:
                try:
                    aviso = interpretar(linea)
                    if aviso:
                        self._al_avisar(aviso)
                except Exception as exc:   # un aviso raro no puede matar la escucha
                    logger.warning('Error procesando un aviso CEC: %s', exc)
        except Exception as exc:
            logger.warning('Se cortó la escucha HDMI-CEC: %s', exc)
        if self._proceso is proceso:
            logger.warning('cec-client terminó (código %s): se deja de escuchar al TV.', proceso.poll())
