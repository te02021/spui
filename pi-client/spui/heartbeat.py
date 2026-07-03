"""
Thread de background que envía heartbeat periódicamente al CMS.

El CMS usa esto para marcar estado_conexion='conectado' y registrar
el timestamp de ultimo_heartbeat en la entidad Reproductor.

Si el heartbeat falla (red caída), lo registra como warning y sigue
intentando en el siguiente ciclo — no interrumpe el player.
"""

import logging
import threading

logger = logging.getLogger(__name__)


class HeartbeatSender(threading.Thread):
    def __init__(self, sync_client, interval: int = 60):
        super().__init__(daemon=True, name='heartbeat')
        self._client = sync_client
        self._interval = interval
        self._stop = threading.Event()

    def run(self) -> None:
        logger.info('Heartbeat iniciado (cada %ds)', self._interval)
        while not self._stop.wait(timeout=self._interval):
            self._client.heartbeat()

    def detener(self) -> None:
        self._stop.set()
