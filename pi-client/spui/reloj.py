"""
La hora del reproductor, corregida con la del CMS (noviembre 2026).

La Pi no tiene reloj propio con pila (se decidió no sumar el módulo RTC):
al arrancar, Raspberry Pi OS vuelve a la hora con la que se apagó
(fake-hwclock) hasta que NTP la corrige, y sin internet eso puede no pasar
nunca. Con esa hora el cronograma mostraría otro día y el horario energético
apagaría la pantalla a deshora.

El CMS manda su hora en cada sync y en cada heartbeat (`servidor_ts`, con su
zona horaria). Acá se guarda la diferencia con el reloj de la Pi y se usa para
todo lo que depende de la hora (cronograma, tema automático, horario
energético). El reloj del sistema no se toca: no hace falta root y el cliente
no cambia la configuración del equipo (decisión de Mateo, 02/11/2026).

La hora es "confiable" si el CMS ya la confirmó en esta ejecución o si el
sistema la sincronizó por NTP. Mientras no lo sea, el horario energético no
apaga la pantalla (spui/energia.py): una pantalla prendida de más es mejor
que una apagada en pleno horario de clases.

La zona horaria también sale del CMS (Argentina, sin horario de verano): la
hora local no depende de cómo quedó configurada la Pi.
"""

import logging
import subprocess
import threading
import time
from datetime import datetime, timezone
from typing import Callable, Optional

logger = logging.getLogger(__name__)

# Una diferencia mayor que esto con el CMS se anota en el registro.
_AVISAR_DIFERENCIA_SEG = 60


def _ntp_sincronizado_por_defecto() -> bool:
    """Si systemd ya sincronizó el reloj por NTP (una consulta local, sin red)."""
    try:
        r = subprocess.run(['timedatectl', 'show', '-p', 'NTPSynchronized', '--value'],
                           capture_output=True, timeout=5, check=False)
        return r.stdout.decode().strip() == 'yes'
    except (OSError, subprocess.SubprocessError):
        return False


class Reloj:
    def __init__(self,
                 ahora_sistema: Callable[[], float] = time.time,
                 ntp_sincronizado: Callable[[], bool] = _ntp_sincronizado_por_defecto):
        self._sistema = ahora_sistema
        self._ntp = ntp_sincronizado
        self._lock = threading.Lock()
        self._diferencia: Optional[float] = None       # segundos a sumarle al reloj de la Pi
        self._zona: Optional[timezone] = None
        self._ntp_ok = False

    def registrar_servidor(self, servidor_ts: Optional[str], ida_y_vuelta: float = 0.0) -> None:
        """
        La hora que mandó el CMS en una respuesta. Se toma la mitad del viaje
        de ida y vuelta como demora de la respuesta. Un valor ilegible se ignora.
        """
        if not servidor_ts:
            return
        try:
            servidor = datetime.fromisoformat(str(servidor_ts))
        except ValueError:
            logger.warning('Hora del CMS ilegible: %r', servidor_ts)
            return
        if servidor.tzinfo is None:
            return
        diferencia = servidor.timestamp() + ida_y_vuelta / 2 - self._sistema()
        with self._lock:
            anterior = self._diferencia
            self._diferencia = diferencia
            self._zona = timezone(servidor.utcoffset())
        if abs(diferencia) >= _AVISAR_DIFERENCIA_SEG and (anterior is None or abs(anterior - diferencia) >= _AVISAR_DIFERENCIA_SEG):
            logger.warning('El reloj de la Pi difiere %s de la hora del CMS: se usa la del CMS.', _legible(diferencia))
        elif anterior is None:
            logger.info('Hora confirmada con el CMS (diferencia %.1f s).', diferencia)

    def ahora(self) -> datetime:
        """La hora local corregida (sin zona, como datetime.now())."""
        with self._lock:
            diferencia, zona = self._diferencia, self._zona
        t = self._sistema() + (diferencia or 0.0)
        if zona is None:
            return datetime.fromtimestamp(t)
        return datetime.fromtimestamp(t, zona).replace(tzinfo=None)

    def confiable(self) -> bool:
        """Si la hora se puede creer: el CMS la confirmó, o el sistema la sincronizó por NTP."""
        with self._lock:
            if self._diferencia is not None:
                return True
        if not self._ntp_ok:
            # Una consulta local a systemd; una vez que dio que sí, no se repite.
            self._ntp_ok = self._ntp()
        return self._ntp_ok


def _legible(segundos: float) -> str:
    s = abs(int(segundos))
    if s < 3600:
        return f'{s // 60} min {s % 60} s'
    dias, s = divmod(s, 86400)
    texto = f'{s // 3600} h {s % 3600 // 60} min'
    return f"{dias} {'día' if dias == 1 else 'días'} {texto}" if dias else texto


# El reloj del proceso: lo comparten el sync (que le pasa la hora del CMS),
# el player y el horario energético.
RELOJ = Reloj()
