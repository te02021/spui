"""
Guardia contra contenidos que cierran el reproductor (spui/guardia.py).

Un reinicio del proceso se simula creando otra GuardiaContenido sobre los
mismos directorios, con la nota de "qué se estaba mostrando" todavía ahí.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui.guardia import GuardiaContenido  # noqa: E402

FOTO = {'id': 37, 'titulo': 'Imagen1', 'hash_archivo': 'abc'}


class GuardiaTest(unittest.TestCase):
    def setUp(self):
        self.cache = tempfile.mkdtemp()
        self.memoria = tempfile.mkdtemp()

    def nueva(self, version='v1'):
        return GuardiaContenido(self.cache, version, dir_nota=self.memoria)

    def muere_mostrando(self, contenido, version='v1'):
        g = self.nueva(version)
        g.empezar(contenido)   # ... y el proceso muere acá, sin terminar()
        return g

    def test_una_muerte_no_alcanza_para_saltear(self):
        self.muere_mostrando(FOTO)
        self.assertFalse(self.nueva().salteado(FOTO))

    def test_dos_muertes_seguidas_en_el_mismo_lo_saltean_y_se_informa(self):
        self.muere_mostrando(FOTO)
        self.muere_mostrando(FOTO)
        g = self.nueva()
        self.assertTrue(g.salteado(FOTO))
        self.assertIn('Imagen1', g.problemas()[0])

    def test_si_se_muestra_bien_en_el_medio_la_cuenta_vuelve_a_cero(self):
        self.muere_mostrando(FOTO)
        g = self.nueva()
        g.empezar(FOTO)
        g.terminar(FOTO)
        self.muere_mostrando(FOTO)
        self.assertFalse(self.nueva().salteado(FOTO))

    def test_un_archivo_nuevo_se_vuelve_a_intentar(self):
        self.muere_mostrando(FOTO)
        self.muere_mostrando(FOTO)
        self.assertFalse(self.nueva().salteado({**FOTO, 'hash_archivo': 'otro'}))

    def test_otra_version_del_cliente_limpia_la_lista(self):
        self.muere_mostrando(FOTO)
        self.muere_mostrando(FOTO)
        self.assertFalse(self.nueva('v2').salteado(FOTO))

    def test_un_cierre_normal_no_cuenta(self):
        g = self.nueva()
        g.empezar(FOTO)
        g.terminar(FOTO)
        self.assertFalse(os.path.exists(os.path.join(self.memoria, 'spui_en_curso.json')))
        self.muere_mostrando({'id': 1, 'titulo': 'Otra'})
        self.assertFalse(self.nueva().salteado(FOTO))


if __name__ == '__main__':
    unittest.main()
