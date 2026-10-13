"""
Pruebas del horario energético (encendido/apagado y brillo) sin hardware.

Se simulan los programas externos (cec-client, xrandr, vcgencmd) para tres
pantallas reales del proyecto: el monitor de escritorio sin HDMI-CEC con el que
se desarrolla, un TV con CEC (el del piloto) y un equipo sin forma de controlar
nada. Lo que se verifica es que el gestor use el método que de verdad funciona
y que nada falle en silencio (caso de prueba CP10 del informe).

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import tempfile
import unittest
from datetime import datetime

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui.energia import GestorEnergia  # noqa: E402

XRANDR_QUERY = (
    'Screen 0: minimum 320 x 200, current 1920 x 1080, maximum 7680 x 7680\n'
    'HDMI-1 connected primary 1920x1080+0+0 (normal left inverted right x axis y axis) 527mm x 296mm\n'
    '   1920x1080     60.00*+  50.00\n'
    'HDMI-2 disconnected (normal left inverted right x axis y axis)\n'
)

# Lunes 12/10/2026. Horario: 08:00 a 20:00.
DENTRO = datetime(2026, 10, 12, 12, 0)
FUERA = datetime(2026, 10, 12, 22, 0)


def regla(brillo=100):
    return [{'dia_semana': 1, 'hora_encendido': '08:00', 'hora_apagado': '20:00', 'nivel_brillo': brillo}]


class _Pantalla:
    """Simula los programas externos de una pantalla y registra las llamadas."""

    def __init__(self, cec=False, cec_obedece=True, xrandr=True, vcgencmd=False, falla_xrandr=False):
        self.cec = cec                    # el TV responde a CEC
        self.cec_obedece = cec_obedece    # y cambia de estado cuando se le pide
        self.xrandr = xrandr
        self.vcgencmd = vcgencmd
        self.falla_xrandr = falla_xrandr
        self.estado_tv = 'on'
        self.llamadas: list[list[str]] = []
        self.hay_x = True

    def hay(self, binario):
        return {'cec-client': True, 'xrandr': self.xrandr, 'vcgencmd': self.vcgencmd}.get(binario, False)

    def entorno(self):
        return {'DISPLAY': ':0'} if self.hay_x else None

    def ejecutar(self, args, entrada=None, timeout=10, env=None):
        self.llamadas.append(list(args) + ([f'<{entrada}>'] if entrada else []))
        if args[0] == 'cec-client':
            if not self.cec:
                return 0, 'opening a connection to the CEC adapter...\npower status: unknown\n'
            if entrada == 'pow 0':
                return 0, f'power status: {self.estado_tv}\n'
            if self.cec_obedece:
                self.estado_tv = 'on' if entrada == 'on 0' else 'standby'
            return 0, ''
        if args[0] == 'xrandr':
            if args[1] == '--query':
                return 0, XRANDR_QUERY
            return (1, "xrandr: Configure crtc 0 failed") if self.falla_xrandr else (0, '')
        if args[0] == 'vcgencmd':
            return 0, 'display_power=0'
        return -1, 'no existe'

    def xrandr_args(self):
        return [ll[1:] for ll in self.llamadas if ll[0] == 'xrandr' and ll[1] != '--query']


class _Reloj:
    def __init__(self):
        self.t = 1000.0

    def __call__(self):
        return self.t


def gestor(p: _Pantalla, reloj=None, carpeta=None, software=None) -> GestorEnergia:
    carpeta = carpeta or tempfile.mkdtemp(prefix='spui-test-')
    return GestorEnergia(ejecutar=p.ejecutar, hay=p.hay, ruta_backlight=None,
                         firma_pantalla=lambda: 'una-pantalla',
                         ruta_estado_brillo=os.path.join(carpeta, 'brillo.json'),
                         brillo_software=software,
                         reloj=reloj or _Reloj(), entorno=p.entorno)


class MonitorSinCecTest(unittest.TestCase):
    def test_detecta_que_no_hay_cec_y_usa_la_senal(self):
        g = gestor(_Pantalla(cec=False))
        self.assertFalse(g.capacidades.cec_responde)
        self.assertEqual(g.capacidades.salida_hdmi, 'HDMI-1')
        self.assertEqual(g.capacidades.metodo_apagado, 'senal')
        # xrandr ya no maneja el brillo: en la Pi 4 (KMS) no tiene efecto.
        self.assertNotEqual(g.brillo.metodo, 'xrandr')

    def test_fuera_de_horario_corta_la_senal(self):
        p = _Pantalla(cec=False)
        g = gestor(p)
        g.actualizar_reglas(regla())
        g.aplicar(ahora=FUERA)
        self.assertTrue(g.esta_apagada())
        self.assertIn(['--output', 'HDMI-1', '--off'], p.xrandr_args())

    def test_al_reencender_restablece_la_senal_y_xrandr_no_toca_el_brillo(self):
        p = _Pantalla(cec=False)
        g = gestor(p)
        g.actualizar_reglas(regla(brillo=60))
        g.aplicar(ahora=DENTRO)
        g.aplicar(ahora=FUERA)
        p.llamadas.clear()
        g.aplicar(ahora=DENTRO)
        self.assertEqual(p.xrandr_args(), [['--output', 'HDMI-1', '--auto']])

    def test_avisa_la_limitacion_solo_si_hay_horario(self):
        g = gestor(_Pantalla(cec=False))
        self.assertEqual(g.diagnostico(), [], 'sin horario no hay nada que avisar')
        g.actualizar_reglas(regla())
        aviso = ' '.join(g.diagnostico())
        self.assertIn('no responde a HDMI-CEC', aviso)
        self.assertIn('sin señal', aviso)

    def test_si_xrandr_falla_no_queda_en_silencio(self):
        p = _Pantalla(cec=False, falla_xrandr=True)
        g = gestor(p)
        g.actualizar_reglas(regla())
        g.aplicar(ahora=FUERA)
        self.assertTrue(g.esta_apagada(), 'el player igual deja de reproducir en horario de apagado')
        self.assertTrue(any('No se pudo apagar' in m for m in g.diagnostico()))

    def test_el_fallo_se_borra_cuando_vuelve_a_andar(self):
        p = _Pantalla(cec=False, falla_xrandr=True)
        g = gestor(p)
        g.actualizar_reglas(regla())
        g.aplicar(ahora=FUERA)
        p.falla_xrandr = False
        g.aplicar(ahora=DENTRO)
        self.assertFalse(any('No se pudo' in m for m in g.diagnostico()))

    def test_tras_un_sync_se_pide_verificar_el_brillo_de_la_regla(self):
        p = _Pantalla(cec=False)
        g = gestor(p)
        pedidos = []
        g.brillo.aplicar = lambda nivel, alerta=False, verificar=False: pedidos.append((nivel, verificar))
        g.actualizar_reglas(regla(brillo=60))
        g.aplicar(ahora=DENTRO, verificar_brillo=True)
        g.aplicar(ahora=DENTRO)
        self.assertEqual(pedidos, [(60, True), (60, False)])

    def test_una_alerta_enciende_aunque_sea_horario_de_apagado(self):
        p = _Pantalla(cec=False)
        niveles = []
        g = gestor(p, software=lambda f: niveles.append(f))
        g.actualizar_reglas(regla(brillo=50))
        g.aplicar(ahora=DENTRO)              # se oscurece al 50 %
        g.aplicar(ahora=FUERA)               # se apaga
        p.llamadas.clear()
        g.aplicar(ahora=FUERA, hay_alerta=True)
        self.assertFalse(g.esta_apagada())
        self.assertEqual(p.xrandr_args(), [['--output', 'HDMI-1', '--auto']])
        self.assertEqual(niveles[-1:], [1.0], 'la alerta se ve con el brillo completo')


class TvConCecTest(unittest.TestCase):
    def test_apaga_por_cec_y_no_corta_la_senal(self):
        p = _Pantalla(cec=True)
        g = gestor(p)
        self.assertEqual(g.capacidades.metodo_apagado, 'cec')
        g.actualizar_reglas(regla())
        g.aplicar(ahora=FUERA)
        self.assertEqual(p.estado_tv, 'standby')
        self.assertNotIn(['--output', 'HDMI-1', '--off'], p.xrandr_args())
        self.assertEqual(g.diagnostico(), [], 'con CEC no hay limitación que avisar')

    def test_si_el_tv_no_obedece_se_informa(self):
        p = _Pantalla(cec=True, cec_obedece=False)
        g = gestor(p)
        g.actualizar_reglas(regla())
        g.aplicar(ahora=FUERA)
        self.assertTrue(any('el TV informa' in m for m in g.diagnostico()))


class SinControlTest(unittest.TestCase):
    def test_sin_nada_entra_en_simulacion_y_lo_avisa(self):
        p = _Pantalla(cec=False, xrandr=False)
        g = gestor(p)
        self.assertTrue(g.en_simulacion())
        g.actualizar_reglas(regla(brillo=50))
        g.aplicar(ahora=FUERA)
        self.assertTrue(g.esta_apagada())
        aviso = ' '.join(g.diagnostico())
        self.assertIn('no hay forma de controlar la pantalla', aviso)

    def test_solo_vcgencmd_avisa_que_en_bookworm_no_tiene_efecto(self):
        g = gestor(_Pantalla(cec=False, xrandr=False, vcgencmd=True))
        g.actualizar_reglas(regla())
        self.assertIn('Bookworm', ' '.join(g.diagnostico()))

    def test_sin_x_al_arrancar_se_vuelve_a_detectar_a_los_2_minutos(self):
        p = _Pantalla(cec=False)
        p.hay_x = False
        reloj = _Reloj()
        g = gestor(p, reloj)
        self.assertIsNone(g.capacidades.salida_hdmi)
        p.hay_x = True              # X terminó de arrancar
        reloj.t += 60
        g.aplicar(ahora=DENTRO)
        self.assertIsNone(g.capacidades.salida_hdmi, 'antes de los 2 minutos no se reintenta')
        reloj.t += 61
        g.aplicar(ahora=DENTRO)
        self.assertEqual(g.capacidades.salida_hdmi, 'HDMI-1')


if __name__ == '__main__':
    unittest.main()
