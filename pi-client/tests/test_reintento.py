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


class _Respuesta:
    def __init__(self, codigo, cabeceras):
        self.status_code = codigo
        self.headers = cabeceras


class _ErrorHttp(Exception):
    def __init__(self, codigo, cabeceras=None):
        super().__init__(f'HTTP {codigo}')
        self.response = _Respuesta(codigo, cabeceras or {})


class EsperaTrasFalloTest(unittest.TestCase):
    """Un 429 se reintenta cuando el CMS dice, no en el próximo intervalo (24/10/2026)."""

    def test_un_429_respeta_retry_after(self):
        espera = main._espera_tras_fallo(_ErrorHttp(429, {'Retry-After': '7'}))
        self.assertGreaterEqual(espera, 7)
        self.assertLessEqual(espera, 9)

    def test_un_retry_after_absurdo_se_acota(self):
        self.assertLessEqual(main._espera_tras_fallo(_ErrorHttp(429, {'Retry-After': '9999'})), 62)
        self.assertGreaterEqual(main._espera_tras_fallo(_ErrorHttp(429, {'Retry-After': '-3'})), 1)
        self.assertLessEqual(main._espera_tras_fallo(_ErrorHttp(429, {'Retry-After': 'mañana'})), 17)

    def test_otro_fallo_espera_como_sin_red(self):
        for exc in (_ErrorHttp(500), ConnectionError('sin ruta')):
            espera = main._espera_tras_fallo(exc)
            self.assertGreaterEqual(espera, 20)
            self.assertLessEqual(espera, 40)


if __name__ == '__main__':
    unittest.main()
