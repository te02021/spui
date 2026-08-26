"""
Monitor de conectividad de red para modo offline/fail-safe.

Usa un TCP connect rápido (no HTTP) al host del CMS para determinar si
la red está disponible. Esto es más barato que un HTTP request y detecta
pérdida de red antes de que el timeout de requests expire.

Registra las transiciones online ↔ offline para no spamear el log en
cada ciclo cuando el reproductor lleva tiempo desconectado.
"""

import logging
import socket
import time
from urllib.parse import urlparse

logger = logging.getLogger(__name__)


class NetworkMonitor:
    def __init__(self, api_url: str, timeout: float = 3.0):
        parsed = urlparse(api_url)
        self._host = parsed.hostname or 'localhost'
        self._port = parsed.port or (443 if parsed.scheme == 'https' else 80)
        self._timeout = timeout
        self._estado_anterior: bool | None = None  # None = primer check

    def is_online(self) -> bool:
        """
        Retorna True si el host del CMS es alcanzable.
        Loguea la transición sólo cuando el estado cambia.
        """
        try:
            conn = socket.create_connection((self._host, self._port), timeout=self._timeout)
            conn.close()
            online = True
        except OSError:
            online = False

        if self._estado_anterior is None:
            # Primer check — no es una transición, sólo registrar
            logger.info('Estado de red inicial: %s', 'ONLINE' if online else 'OFFLINE')
        elif online != self._estado_anterior:
            if online:
                logger.info('Red RESTAURADA — volviendo al modo online. Re-sincronizando.')
            else:
                logger.warning(
                    'Red PERDIDA — entrando en modo OFFLINE. '
                    'Usando caché SQLite hasta recuperar conexión.'
                )

        self._estado_anterior = online
        return online

    def medir_latencia_ms(self) -> float | None:
        """
        Tiempo de un TCP connect al host del CMS, en milisegundos — es la
        métrica 'latencia_red_ms' de la telemetría (antes siempre None).

        Mismo host/puerto y mismo mecanismo que is_online(), pero medido en
        vez de sólo comprobado: no tiene sentido abrir una segunda conexión
        con otra lógica para esto.

        None si no se pudo conectar. Forzar un número (por ejemplo 9999) para
        el caso caído ensuciaría el promedio de telemetria_hora con un outlier
        que no representa ninguna latencia real — mejor un hueco que un dato
        falso, mismo criterio que temperatura/RAM.
        """
        inicio = time.perf_counter()
        try:
            conn = socket.create_connection((self._host, self._port), timeout=self._timeout)
            conn.close()
        except OSError:
            return None

        return round((time.perf_counter() - inicio) * 1000, 1)
