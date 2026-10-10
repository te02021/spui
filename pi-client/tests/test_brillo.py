"""
Pruebas del brillo con cualquier pantalla (spui/brillo.py), sin hardware.

Se simula ddcutil para un monitor con DDC/CI como el Philips 221V8 de banco
(brillo 67 de 100), un TV sin DDC/CI, y los cambios hechos a mano con los
botones del monitor. Las reglas que se verifican son las que fijó Mateo el
10/10/2026: el % del CMS es relativo al brillo que dejó el instalador; un
cambio manual fuera de regla pasa a ser la referencia; durante una regla se
respeta 30 s y después manda la regla.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import json
import os
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui.brillo import ControlBrillo  # noqa: E402

DETECT_PHILIPS = (
    'Display 1\n'
    '   I2C bus:  /dev/i2c-20\n'
    '   DRM connector:           card1-HDMI-A-1\n'
    '   EDID synopsis:\n'
    '      Mfg id:               PHL - Philips Consumer Electronics Company\n'
    '      Model:                PHL 221V8\n'
    '      Binary serial number: 1795 (0x00000703)\n'
    '   VCP version:         2.2\n'
)
DETECT_SIN_DDC = (
    'Invalid display\n'
    '   I2C bus:  /dev/i2c-20\n'
    '   DDC communication failed\n'
)


class _Monitor:
    """ddcutil falso: un monitor con brillo propio que se puede tocar a mano."""

    def __init__(self, ddc=True, brillo=67, maximo=100, aviso_02=True, ignora_escritura=False):
        self.ddc, self.brillo, self.maximo = ddc, brillo, maximo
        self.aviso_02 = aviso_02          # implementa VCP 0x02
        self.ignora_escritura = ignora_escritura
        self.cambiado = False             # estado de VCP 0x02
        self.escrituras: list[int] = []
        self.lecturas_brillo = 0

    def tocar_botones(self, valor):
        self.brillo = valor
        self.cambiado = True

    def hay(self, binario):
        return binario == 'ddcutil'

    def ejecutar(self, args, entrada=None, timeout=10, env=None):
        if args[:2] == ['ddcutil', 'detect']:
            return (0, DETECT_PHILIPS) if self.ddc else (1, DETECT_SIN_DDC)
        if 'getvcp' in args and args[-1] == '10':
            self.lecturas_brillo += 1
            return 0, f'VCP 10 C {self.brillo} {self.maximo}\n'
        if 'getvcp' in args and args[-1] == '02':
            if not self.aviso_02:
                return 1, 'Feature 02 not supported'
            return 0, f'VCP 02 SNC x{"02" if self.cambiado else "01"}\n'
        if 'setvcp' in args and args[-2] == '10':
            self.escrituras.append(int(args[-1]))
            if not self.ignora_escritura:
                self.brillo = int(args[-1])
            return 0, ''
        if 'setvcp' in args and args[-2] == '02':
            self.cambiado = False
            return 0, ''
        return 1, 'comando no simulado'


class _Temporizador:
    """Reemplaza threading.Timer: guarda lo programado y lo dispara a pedido."""

    def __init__(self):
        self.pendientes = []

    def __call__(self, segundos, funcion):
        self.pendientes.append((segundos, funcion))
        return self

    def cancel(self):
        self.pendientes.clear()

    def disparar(self):
        pendientes, self.pendientes = self.pendientes, []
        for _, f in pendientes:
            f()


class BaseTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp(prefix='spui-test-')
        self.estado = os.path.join(self.dir, 'brillo.json')
        self.reloj = _Temporizador()

    def tearDown(self):
        shutil.rmtree(self.dir, ignore_errors=True)

    def control(self, monitor, software=None):
        c = ControlBrillo(monitor.ejecutar, monitor.hay, None, self.estado,
                          software=software, temporizador=self.reloj)
        c.detectar()
        return c


class MonitorConDdcTest(BaseTest):
    def test_usa_ddc_y_toma_el_brillo_actual_como_referencia(self):
        c = self.control(_Monitor(brillo=67))
        self.assertEqual(c.metodo, 'ddc')
        with open(self.estado) as fh:
            self.assertEqual(json.load(fh)['PHL / PHL 221V8 / 1795']['referencia'], 67)

    def test_sin_regla_no_toca_el_monitor(self):
        m = _Monitor(brillo=67)
        c = self.control(m)
        c.aplicar(None)
        self.assertEqual(m.escrituras, [])
        self.assertEqual(m.brillo, 67)

    def test_el_porcentaje_del_cms_es_relativo_a_la_referencia(self):
        m = _Monitor(brillo=50)
        c = self.control(m)
        c.aplicar(80)
        self.assertEqual(m.brillo, 40, '80 % de 50')

    def test_brillo_cero_se_respeta(self):
        m = _Monitor(brillo=67)
        c = self.control(m)
        c.aplicar(0)
        self.assertEqual(m.brillo, 0)

    def test_al_terminar_la_regla_vuelve_a_la_referencia(self):
        m = _Monitor(brillo=50)
        c = self.control(m)
        c.aplicar(80)
        c.aplicar(None)
        self.assertEqual(m.brillo, 50)

    def test_una_alerta_usa_el_maximo_del_monitor(self):
        m = _Monitor(brillo=50, maximo=100)
        c = self.control(m)
        c.aplicar(30)
        c.aplicar(None, alerta=True)
        self.assertEqual(m.brillo, 100)

    def test_cambio_manual_fuera_de_regla_es_la_nueva_referencia(self):
        m = _Monitor(brillo=50)
        c = self.control(m)
        c.aplicar(None)
        m.tocar_botones(90)
        c.revisar_cambio_manual()
        c.aplicar(50)                        # empieza una regla de 50 %
        self.assertEqual(m.brillo, 45, '50 % de la nueva referencia (90)')
        with open(self.estado) as fh:
            self.assertEqual(json.load(fh)['PHL / PHL 221V8 / 1795']['referencia'], 90)

    def test_cambio_manual_antes_de_que_empiece_la_regla_se_toma_como_referencia(self):
        # Lo tocaron fuera de horario y nadie lo vio hasta que arrancó la regla:
        # el momento clave (antes de escribir) lo detecta igual.
        m = _Monitor(brillo=50)
        c = self.control(m)
        c.aplicar(None)
        m.tocar_botones(80)
        c.aplicar(50)
        self.assertEqual(m.brillo, 40)

    def test_cambio_manual_durante_una_regla_se_respeta_30_s_y_vuelve_la_regla(self):
        m = _Monitor(brillo=50)
        c = self.control(m)
        c.aplicar(80)                        # regla: 40
        m.tocar_botones(70)
        c.revisar_cambio_manual()
        self.assertEqual(m.brillo, 70, 'se deja el cambio manual')
        self.assertEqual(self.reloj.pendientes[0][0], 30)
        self.assertTrue(any('a mano' in p for p in c.diagnostico(True)), 'se avisa en el CMS')
        c.aplicar(80)                        # un sync en medio no lo pisa antes de tiempo
        self.assertEqual(m.brillo, 70)
        self.reloj.disparar()                # pasaron los 30 s
        self.assertEqual(m.brillo, 40, 'la regla del CMS manda')
        self.assertFalse(any('a mano' in p for p in c.diagnostico(True)))
        with open(self.estado) as fh:
            self.assertEqual(json.load(fh)['PHL / PHL 221V8 / 1795']['referencia'], 50,
                             'un cambio durante la regla no cambia la referencia')

    def test_si_termina_la_regla_antes_de_los_30_s_se_cancela_la_vuelta(self):
        m = _Monitor(brillo=50)
        c = self.control(m)
        c.aplicar(80)
        m.tocar_botones(70)
        c.revisar_cambio_manual()
        c.aplicar(None)                      # terminó la regla
        self.assertEqual(self.reloj.pendientes, [])

    def test_con_aviso_0x02_no_se_lee_el_brillo_si_nadie_toco_nada(self):
        m = _Monitor(brillo=50, aviso_02=True)
        c = self.control(m)
        c.aplicar(None)
        m.lecturas_brillo = 0
        c.revisar_cambio_manual()
        self.assertEqual(m.lecturas_brillo, 0, 'el monitor dijo que nadie tocó nada')
        m.tocar_botones(60)
        c.revisar_cambio_manual()
        self.assertEqual(m.lecturas_brillo, 1)
        self.assertFalse(m.cambiado, 'se bajó el aviso para enterarse del próximo cambio')

    def test_sin_aviso_0x02_se_lee_el_brillo(self):
        m = _Monitor(brillo=50, aviso_02=False)
        c = self.control(m)
        c.aplicar(None)
        m.lecturas_brillo = 0
        c.revisar_cambio_manual()
        self.assertEqual(m.lecturas_brillo, 1)

    def test_cambio_con_el_servicio_apagado_se_toma_al_arrancar(self):
        m = _Monitor(brillo=50)
        self.control(m).aplicar(None)
        m.brillo = 75                        # lo tocaron con la Pi apagada
        c = self.control(m)
        c.aplicar(100)
        self.assertEqual(m.brillo, 75, '100 % de la nueva referencia')

    def test_si_el_monitor_no_obedece_se_informa(self):
        m = _Monitor(brillo=50, ignora_escritura=True)
        c = self.control(m)
        c.aplicar(50)
        self.assertTrue(any('quedó en 50' in p for p in c.diagnostico(True)))


class SinDdcTest(BaseTest):
    def test_tv_sin_ddc_oscurece_la_imagen_y_lo_avisa(self):
        niveles = []
        c = self.control(_Monitor(ddc=False), software=lambda f: niveles.append(f))
        self.assertEqual(c.metodo, 'software')
        c.aplicar(40)
        self.assertEqual(niveles, [0.4])
        aviso = ' '.join(c.diagnostico(hay_reglas_atenuadas=True))
        self.assertIn('no responde a DDC/CI', aviso)
        self.assertIn('no reduce el consumo', aviso)

    def test_sin_reglas_atenuadas_no_avisa(self):
        c = self.control(_Monitor(ddc=False), software=lambda f: None)
        self.assertEqual(c.diagnostico(hay_reglas_atenuadas=False), [])

    def test_no_reenvia_el_mismo_brillo_en_cada_ciclo(self):
        niveles = []
        c = self.control(_Monitor(ddc=False), software=lambda f: niveles.append(f))
        for _ in range(3):
            c.aplicar(40)
        self.assertEqual(niveles, [0.4])

    def test_si_aparece_un_monitor_con_ddc_se_deshace_el_oscurecido(self):
        niveles = []
        m = _Monitor(ddc=False)
        c = self.control(m, software=lambda f: niveles.append(f))
        c.aplicar(40)
        m.ddc = True
        c.detectar()
        self.assertEqual(c.metodo, 'ddc')
        self.assertEqual(niveles[-1], 1.0)

    def test_sin_permiso_sobre_i2c_lo_dice(self):
        m = _Monitor()
        m.ejecutar = lambda *a, **k: (1, 'Open failed for /dev/i2c-20: errno=EACCES(13): Permission denied')
        c = self.control(m)
        self.assertIn('grupo i2c', c.motivo_sin_control)


if __name__ == '__main__':
    unittest.main()
