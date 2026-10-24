"""
La playlist gira sin pausa: al terminar el último contenido sigue el primero,
y el sync se hace entre vueltas cuando le toca (main._girar_playlist).

Antes se hacía una vuelta y después se esperaba el intervalo de sync entero, y
la pantalla se quedaba en el último cuadro del último video hasta 5 minutos.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import time
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

import main  # noqa: E402


class PlayerFalso:
    """Cada vuelta 'dura' `vuelta` segundos; cuenta cuántas se hicieron."""

    def __init__(self, vuelta: float = 0.0, alerta_en: int | None = None, al_terminar=None):
        self.vuelta = vuelta
        self.vueltas = 0
        self.alerta_en = alerta_en
        self.al_terminar = al_terminar

    def reproducir_playlist(self, items, sync_client=None):
        time.sleep(self.vuelta)
        self.vueltas += 1
        if self.al_terminar:
            self.al_terminar(self.vueltas)

    def esta_mostrando_alerta(self):
        return self.alerta_en is not None and self.vueltas >= self.alerta_en


class GiroTest(unittest.TestCase):
    def setUp(self):
        main._despertar.clear()
        self.addCleanup(main._despertar.clear)

    def test_vuelve_al_primero_sin_esperar_al_sync(self):
        p = PlayerFalso(vuelta=1.1)
        inicio = time.monotonic()
        main._girar_playlist(p, ['a', 'b'], None, hasta=inicio + 3)
        self.assertEqual(p.vueltas, 3)                        # 1,1 + 1,1 + 1,1 ≥ 3: tres vueltas seguidas
        self.assertLess(time.monotonic() - inicio, 3.8)       # sin ninguna espera entre vueltas

    def test_un_sync_ahora_corta_entre_vueltas(self):
        p = PlayerFalso(vuelta=1.1, al_terminar=lambda n: n == 1 and main._despertar.set())
        main._girar_playlist(p, ['a'], None, hasta=time.monotonic() + 60)
        self.assertEqual(p.vueltas, 1)

    def test_una_alerta_corta_el_giro(self):
        p = PlayerFalso(vuelta=1.1, alerta_en=2)
        main._girar_playlist(p, ['a'], None, hasta=time.monotonic() + 60)
        self.assertEqual(p.vueltas, 2)

    def test_un_permanente_no_gira_en_vacio(self):
        p = PlayerFalso(vuelta=0.0)          # reproducir_playlist vuelve en el acto
        main._girar_playlist(p, ['a'], None, hasta=time.monotonic() + 60)
        self.assertEqual(p.vueltas, 1)


if __name__ == '__main__':
    unittest.main()
