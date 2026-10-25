"""
Hilo de reproducción (spui/reproduccion.py), con un player falso:

  - la playlist gira sin pausa y un cambio de playlist corta en el acto;
  - el mismo sync que trae la misma playlist no corta nada;
  - una alerta corta la playlist y al desactivarse se vuelve a ella;
  - un sync del caché (sin red o un 429) no revive una alerta ya desactivada,
    que es lo que pasó el 24/10/2026.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import threading
import time
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui.reproduccion import APAGADO, ESPERA, PLAYLIST, Reproduccion  # noqa: E402


class PlayerFalso:
    """Registra qué muestra; cada ítem 'dura' lo que dice su 'seg'."""

    def __init__(self):
        self.vistos: list[str] = []
        self._alerta = None
        self._lock = threading.Lock()
        self._interrumpido = threading.Event()
        self._stop = threading.Event()
        self.paradas = 0

    @staticmethod
    def _firma_playlist(items):
        return '|'.join(i['id'] for i in items)

    def interrumpir(self):
        self._interrumpido.set()

    def limpiar_interrupcion(self):
        self._interrumpido.clear()

    def marcar_alerta(self, alerta):
        with self._lock:
            self._alerta = alerta
        self._interrumpido.set()

    def desactivar_alerta(self):
        with self._lock:
            self._alerta = None

    def mostrar_alerta(self, alerta):
        self.vistos.append('ALERTA')
        while self._alerta is alerta and not self._stop.is_set():
            time.sleep(0.02)

    def reproducir_playlist(self, items, sync_client=None):
        for it in items:
            if self._alerta is not None or self._interrumpido.is_set() or self._stop.is_set():
                return True
            self.vistos.append(it['id'])
            fin = time.monotonic() + it['seg']
            while time.monotonic() < fin:
                if self._alerta is not None or self._interrumpido.is_set() or self._stop.is_set():
                    return True
                time.sleep(0.01)
        return True

    def mostrar_fallback(self):
        self.vistos.append('ESPERA')

    def _parar_vlc(self):
        self.paradas += 1

    def reintentar_pantalla(self):
        return True

    def detener(self):
        pass


def item(id_, seg=0.3):
    return {'id': id_, 'seg': seg}


class ReproduccionTest(unittest.TestCase):
    def setUp(self):
        self.p = PlayerFalso()
        self.r = Reproduccion(self.p)
        self.r.start()
        self.addCleanup(self.r.detener)

    def mostrado(self):
        """Lo que se vio, sin la pantalla de espera del arranque."""
        return [v for v in self.p.vistos if v != 'ESPERA']

    def esperar(self, condicion, limite=2.0):
        fin = time.monotonic() + limite
        while time.monotonic() < fin:
            if condicion():
                return True
            time.sleep(0.01)
        return False

    def test_la_playlist_gira_sin_pausa(self):
        self.r.programar(PLAYLIST, [item('a'), item('b')])
        self.assertTrue(self.esperar(lambda: self.p.vistos.count('a') >= 3, 3.0))
        self.assertEqual(self.mostrado()[:4], ['a', 'b', 'a', 'b'])

    def test_otra_playlist_corta_en_el_acto(self):
        self.r.programar(PLAYLIST, [item('largo', 30)])
        self.assertTrue(self.esperar(lambda: 'largo' in self.p.vistos))
        inicio = time.monotonic()
        self.r.programar(PLAYLIST, [item('nuevo', 0.2)])
        self.assertTrue(self.esperar(lambda: 'nuevo' in self.p.vistos, 1.0))
        self.assertLess(time.monotonic() - inicio, 0.5)

    def test_la_misma_playlist_no_corta_lo_que_se_muestra(self):
        lista = [item('largo', 0.6), item('b', 0.1)]
        self.r.programar(PLAYLIST, lista)
        self.assertTrue(self.esperar(lambda: 'largo' in self.p.vistos))
        self.r.programar(PLAYLIST, [dict(x) for x in lista])   # el sync de cada minuto
        self.assertTrue(self.esperar(lambda: 'b' in self.p.vistos))
        self.assertEqual(self.mostrado()[:2], ['largo', 'b'])

    def test_una_alerta_corta_y_al_quitarla_se_vuelve_a_la_playlist(self):
        self.r.programar(PLAYLIST, [item('a', 30)])
        self.assertTrue(self.esperar(lambda: 'a' in self.p.vistos))
        self.r.alerta({'id': 22})
        self.assertTrue(self.esperar(lambda: 'ALERTA' in self.p.vistos, 0.5))
        self.r.quitar_alerta()
        self.assertTrue(self.esperar(lambda: self.p.vistos.count('a') >= 2, 1.0))
        self.assertFalse(self.r.mostrando_alerta())

    def test_la_misma_alerta_otra_vez_no_reinicia_la_pantalla(self):
        self.r.alerta({'id': 22})
        self.assertTrue(self.esperar(lambda: 'ALERTA' in self.p.vistos))
        self.r.alerta({'id': 22})   # el sync de cada minuto la sigue informando
        time.sleep(0.2)
        self.assertEqual(self.p.vistos.count('ALERTA'), 1)

    def test_un_sync_del_cache_no_revive_una_alerta_desactivada(self):
        ultimo_sync_ok = time.monotonic()
        self.r.alerta({'id': 22})
        self.r.quitar_alerta()
        # 429 → el cliente usa el sync guardado, que todavía trae la alerta
        self.r.alerta_segun_sync({'id': 22}, fresco=False, sync_ok_desde=ultimo_sync_ok)
        self.assertFalse(self.r.mostrando_alerta())

    def test_al_arrancar_sin_red_el_cache_si_muestra_la_alerta(self):
        self.r.alerta_segun_sync({'id': 22}, fresco=False, sync_ok_desde=0.0)
        self.assertTrue(self.r.mostrando_alerta())

    def test_un_sync_del_cache_sin_alerta_no_saca_la_de_mqtt(self):
        self.r.alerta({'id': 22})
        self.r.alerta_segun_sync(None, fresco=False, sync_ok_desde=0.0)
        self.assertTrue(self.r.mostrando_alerta())

    def test_un_sync_nuevo_sin_alerta_la_saca_pasada_la_gracia(self):
        self.r.alerta({'id': 22})
        self.r.alerta_segun_sync(None, fresco=True, sync_ok_desde=time.monotonic())
        self.assertTrue(self.r.mostrando_alerta())          # recién llegada por MQTT: el sync pudo ser anterior
        self.r._alta_alerta -= 60
        self.r.alerta_segun_sync(None, fresco=True, sync_ok_desde=time.monotonic())
        self.assertFalse(self.r.mostrando_alerta())

    def test_apagado_y_espera(self):
        self.r.programar(PLAYLIST, [item('a', 30)])
        self.assertTrue(self.esperar(lambda: 'a' in self.p.vistos))
        self.r.programar(APAGADO)
        self.assertTrue(self.esperar(lambda: self.p.paradas >= 1, 0.5))
        self.r.programar(ESPERA)
        self.assertTrue(self.esperar(lambda: 'ESPERA' in self.p.vistos, 0.5))


if __name__ == '__main__':
    unittest.main()
