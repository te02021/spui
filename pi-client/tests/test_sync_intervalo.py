"""
Pruebas del intervalo de sync que fija el CMS (proxima_sync_seg), sin red.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui.sync import MINIMO_SYNC_SEG, intervalo_desde_sync  # noqa: E402


class IntervaloDesdeSyncTest(unittest.TestCase):
    def test_usa_el_intervalo_del_cms(self):
        self.assertEqual(intervalo_desde_sync({'proxima_sync_seg': 120}, 300), 120)

    def test_intervalo_largo_se_respeta(self):
        self.assertEqual(intervalo_desde_sync({'proxima_sync_seg': 7 * 24 * 3600}, 300), 7 * 24 * 3600)

    def test_por_debajo_del_minimo_se_sube_al_minimo(self):
        # El CMS acepta 2 syncs por minuto: menos de 30 s terminaría en 429.
        self.assertEqual(intervalo_desde_sync({'proxima_sync_seg': 5}, 300), MINIMO_SYNC_SEG)

    def test_sin_dato_o_invalido_sigue_con_el_actual(self):
        for datos in ({}, {'proxima_sync_seg': None}, {'proxima_sync_seg': 'mucho'}, {'proxima_sync_seg': 0},
                      {'proxima_sync_seg': -60}, None):
            self.assertEqual(intervalo_desde_sync(datos, 300), 300, datos)


if __name__ == '__main__':
    unittest.main()
