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
