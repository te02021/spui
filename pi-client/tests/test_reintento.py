"""
Reintento sin red con dispersión al azar (jitter): al volver la red de un
edificio, las Pis no reintentan todas en el mismo instante.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

import main  # noqa: E402


class EsperaReintentoTest(unittest.TestCase):
    def test_queda_dentro_de_30_mas_menos_10(self):
        for _ in range(2000):
            espera = main._espera_reintento()
            self.assertGreaterEqual(espera, 20)
            self.assertLessEqual(espera, 40)

    def test_no_es_siempre_la_misma(self):
        esperas = {round(main._espera_reintento(), 1) for _ in range(200)}
        self.assertGreater(len(esperas), 50, 'si fueran todas iguales no dispersaría nada')


if __name__ == '__main__':
    unittest.main()
