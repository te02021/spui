"""
Resolución fija (03/11/2026): un TV de 42" no le informaba a la Pi sus
resoluciones y X arrancaba en 1024x768, con franjas negras. SPUI_RESOLUCION
la impone con xrandr, al detectar la pantalla y después de cada encendido por
horario (--auto volvía a la "preferida").

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui import resolucion  # noqa: E402
from spui.energia import GestorEnergia  # noqa: E402
from test_energia import DENTRO, FUERA, _Reloj, regla  # noqa: E402

# Lo que dio la Pi de Mateo con el TV de 42" (03/11/2026), ya con video= en cmdline.txt.
QUERY_TV42 = (
    'Screen 0: minimum 320 x 200, current 1024 x 768, maximum 7680 x 7680\n'
    'HDMI-1 connected primary 1024x768+0+0 (normal left inverted right x axis y axis) 0mm x 0mm\n'
    '   1920x1080     60.00\n'
    '   1024x768      60.00*\n'
    '   800x600       60.32    56.25\n'
    'HDMI-2 disconnected (normal left inverted right x axis y axis)\n'
)
# El mismo TV sin el video= (sin 1920x1080 en la lista).
QUERY_SIN_MODO = QUERY_TV42.replace('   1920x1080     60.00\n', '')


class LeerTest(unittest.TestCase):
    def test_lee_la_variable(self):
        self.assertEqual(resolucion.leer('1920x1080'), (1920, 1080))
        self.assertEqual(resolucion.leer(' 1280 X 720 '), (1280, 720))

    def test_vacia_o_mal_escrita_no_impone_nada(self):
        for malo in (None, '', 'full hd', '1920', '1920x1080x60'):
            self.assertIsNone(resolucion.leer(malo))


class PasosTest(unittest.TestCase):
    def test_si_el_tv_la_lista_solo_se_elige(self):
        pasos, motivo = resolucion.pasos(QUERY_TV42, 'HDMI-1', 1920, 1080)
        self.assertEqual((pasos, motivo), ([['--output', 'HDMI-1', '--mode', '1920x1080']], None))

    def test_si_no_la_lista_se_agrega_con_el_modo_estandar(self):
        pasos, motivo = resolucion.pasos(QUERY_SIN_MODO, 'HDMI-1', 1920, 1080)
        self.assertIsNone(motivo)
        self.assertEqual([p[0] for p in pasos], ['--newmode', '--addmode', '--output'])
        self.assertIn('148.50', pasos[0])

    def test_si_ya_esta_no_hace_nada(self):
        ya = QUERY_TV42.replace('1920x1080     60.00\n', '1920x1080     60.00*\n').replace('1024x768      60.00*', '1024x768      60.00')
        self.assertEqual(resolucion.pasos(ya, 'HDMI-1', 1920, 1080), ([], None))

    def test_una_rara_sin_cvt_lo_dice(self):
        pasos, motivo = resolucion.pasos(QUERY_SIN_MODO, 'HDMI-1', 1600, 900)
        self.assertEqual(pasos, [])
        self.assertIn('cvt', motivo)

    def test_una_rara_con_cvt(self):
        salida_cvt = '# 1600x900 59.95 Hz\nModeline "1600x900_60.00"  118.25  1600 1696 1856 2112  900 903 908 934 -hsync +vsync\n'
        pasos, _ = resolucion.pasos(QUERY_SIN_MODO, 'HDMI-1', 1600, 900, lambda a, b: resolucion.cvt_desde(salida_cvt))
        self.assertEqual(pasos[0][:3], ['--newmode', '1600x900_spui', '118.25'])


class _TvSinEdid:
    """Un TV que no informa sus resoluciones: xrandr cambia de modo cuando se lo piden."""

    def __init__(self):
        self.actual = '1024x768'
        self.ordenes: list[list[str]] = []

    def hay(self, binario):
        return binario == 'xrandr'

    def ejecutar(self, args, entrada=None, timeout=10, env=None):
        if args[0] != 'xrandr':
            return -1, ''
        if args[1] == '--query':
            q = QUERY_TV42.replace('*', '')
            return 0, q.replace(f'   {self.actual}', f'   {self.actual}').replace(f'{self.actual}      60.00', f'{self.actual}      60.00*').replace(f'{self.actual}     60.00', f'{self.actual}     60.00*')
        self.ordenes.append(args[1:])
        if args[1:3] == ['--output', 'HDMI-1'] and args[3] == '--mode':
            self.actual = args[4]
        if args[3:] == ['--auto']:
            self.actual = '1024x768'    # la "preferida" de un TV sin EDID
        return 0, ''


class EnergiaConResolucionTest(unittest.TestCase):
    def _gestor(self, resol=(1920, 1080)):
        self.tv = _TvSinEdid()
        self.avisos = mock.Mock()
        g = GestorEnergia(ejecutar=self.tv.ejecutar, hay=self.tv.hay, ruta_backlight=None,
                          firma_pantalla=lambda: 'tv', entorno=lambda: {'DISPLAY': ':0'},
                          ruta_estado_brillo=os.path.join(tempfile.mkdtemp(), 'brillo.json'),
                          reloj=_Reloj(), resolucion=resol, al_cambiar_resolucion=self.avisos)
        g.actualizar_reglas(regla())
        return g

    def test_al_detectar_la_pantalla_la_pone_y_avisa(self):
        self._gestor()
        self.assertEqual(self.tv.actual, '1920x1080')
        self.avisos.assert_called_once()

    def test_si_ya_esta_no_vuelve_a_tocarla(self):
        g = self._gestor()
        self.tv.ordenes.clear()
        g.aplicar_resolucion()
        self.assertEqual(self.tv.ordenes, [])
        self.assertEqual(self.avisos.call_count, 1)

    def test_despues_de_un_apagado_por_horario_vuelve_a_1920(self):
        g = self._gestor()
        g.aplicar(ahora=FUERA)                 # corte de señal
        g.aplicar(ahora=DENTRO)                # --auto → 1024x768 → se repone
        self.assertEqual(self.tv.actual, '1920x1080')
        self.assertEqual(self.avisos.call_count, 2)

    def test_sin_la_variable_no_se_toca_nada(self):
        self._gestor(resol=None)
        self.assertEqual(self.tv.actual, '1024x768')
        self.avisos.assert_not_called()


class VentanaReabreTest(unittest.TestCase):
    def test_se_reabre_una_vez_si_cambia_el_escritorio(self):
        from spui.player import Player
        p = Player.__new__(Player)
        import threading
        p._interrumpido = threading.Event()
        p._video_activo = False
        ventana = mock.Mock()
        p._ventana = ventana
        p._abrir_ventana = mock.Mock()
        p.pantalla_cambio()
        p._rehacer_ventana()
        ventana.cerrar.assert_called_once()
        p._abrir_ventana.assert_called_once()
        p._rehacer_ventana()            # sin otro aviso, no se vuelve a abrir
        p._abrir_ventana.assert_called_once()


if __name__ == '__main__':
    unittest.main()
