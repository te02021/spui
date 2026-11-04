"""
Versión desplegada: deploy.ps1 escribe /opt/spui/VERSION y el cliente la
muestra al arrancar. Sin el archivo (una instalación hecha a mano) no tiene
que fallar.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

import config  # noqa: E402


class VersionTest(unittest.TestCase):
    def test_lee_el_archivo(self):
        with tempfile.TemporaryDirectory() as d:
            with open(os.path.join(d, 'VERSION'), 'w', encoding='utf-8') as f:
                f.write('e37e69d 21/10/2026 08:18\n')
            self.assertEqual(config._version(d), 'e37e69d 21/10/2026 08:18')

    def test_sin_archivo_no_falla(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(config._version(d), 'desconocida')

    def test_archivo_vacio(self):
        with tempfile.TemporaryDirectory() as d:
            open(os.path.join(d, 'VERSION'), 'w').close()
            self.assertEqual(config._version(d), 'desconocida')


if __name__ == '__main__':
    unittest.main()
