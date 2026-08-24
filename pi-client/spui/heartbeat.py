"""
Thread de background que envía heartbeat periódicamente al CMS.

El CMS usa esto para marcar estado_conexion='conectado' y registrar
el timestamp de ultimo_heartbeat en la entidad Reproductor.

Además viaja el diagnóstico: los problemas que el reproductor detecta por su
cuenta y que el operador tiene que poder ver en el panel, sin entrar por SSH.

Si el heartbeat falla (red caída), lo registra como warning y sigue
intentando en el siguiente ciclo — no interrumpe el player.
"""

import logging
import threading
from typing import Callable, Optional

logger = logging.getLogger(__name__)


class HeartbeatSender(threading.Thread):
    def __init__(
        self,
        sync_client,
        interval: int = 60,
        recolectar_diagnostico: Optional[Callable[[], list[str]]] = None,
    ):
        super().__init__(daemon=True, name='heartbeat')
        self._client = sync_client
        self._interval = interval
        self._stop = threading.Event()
        # Se pide en cada envío (y no una sola vez al arrancar) para que un
        # problema que aparece o se resuelve más tarde se refleje en el panel.
        self._recolectar = recolectar_diagnostico

    def run(self) -> None:
        logger.info('Heartbeat iniciado (cada %ds)', self._interval)

        # Primer envío inmediato: si esperáramos el intervalo completo, el
        # reproductor figuraría como desconectado en el dashboard durante el
        # primer minuto después de arrancar, que es justo cuando se lo mira.
        self._enviar()

        while not self._stop.wait(timeout=self._interval):
            self._enviar()

    def _enviar(self) -> None:
        """
        SyncClient.heartbeat() ya captura los errores de red, pero cualquier
        otra excepción que se escape mataría este hilo en silencio: el
        reproductor seguiría reproduciendo y el dashboard lo mostraría como
        desconectado para siempre, sin ninguna pista del motivo.
        """
        try:
            diagnostico: list[str] = []
            if self._recolectar is not None:
                try:
                    diagnostico = self._recolectar()
                except Exception:
                    # Que falle la recolección no puede impedir el heartbeat:
                    # sin heartbeat el equipo figura desconectado, que es peor.
                    logger.exception('No se pudo recolectar el diagnóstico.')

            self._client.heartbeat(diagnostico)
        except Exception:
            logger.exception('Heartbeat falló de forma inesperada — se reintenta en el próximo ciclo.')

    def detener(self) -> None:
        self._stop.set()
