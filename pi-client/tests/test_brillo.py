"""
Pruebas del brillo con cualquier pantalla (spui/brillo.py), sin hardware.

Se simula ddcutil para un monitor con DDC/CI como el Philips 221V8 de banco
(brillo 67 de 100), un TV sin DDC/CI, y los cambios hechos a mano con los
botones del monitor. Las reglas que se verifican son las que fijó Mateo el
10/10/2026: el % del CMS es relativo al brillo real de la pantalla; nunca se
la consulta periódicamente, sólo en los momentos clave (empieza, cambia o
termina una regla o una alerta), y ahí se toma cualquier cambio hecho a mano.

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
        self.llamadas = 0                 # cuántas veces se llamó a ddcutil
        self.lecturas_brillo = 0

    def tocar_botones(self, valor):
        self.brillo = valor
        self.cambiado = True

    def hay(self, binario):
        return binario == 'ddcutil'

    def ejecutar(self, args, entrada=None, timeout=10, env=None):
        self.llamadas += 1
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


class BaseTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp(prefix='spui-test-')
        self.estado = os.path.join(self.dir, 'brillo.json')

    def tearDown(self):
        shutil.rmtree(self.dir, ignore_errors=True)

    def control(self, monitor, software=None):
        c = ControlBrillo(monitor.ejecutar, monitor.hay, None, self.estado, software=software)
        c.detectar()
        return c

    def referencia(self):
        with open(self.estado) as fh:
            return json.load(fh)['PHL / PHL 221V8 / 1795']['referencia']


class MonitorConDdcTest(BaseTest):
    def test_usa_ddc_y_toma_el_brillo_actual_como_referencia(self):
        c = self.control(_Monitor(brillo=67))
        self.assertEqual(c.metodo, 'ddc')
        self.assertEqual(self.referencia(), 67)

    def test_sin_regla_no_toca_el_monitor(self):
        m = _Monitor(brillo=67)
        c = self.control(m)
        c.aplicar(None)
        self.assertEqual(m.escrituras, [])
        self.assertEqual(m.brillo, 67)

    def test_aplicar_lo_mismo_no_consulta_la_pantalla(self):
        # Pedido de Mateo: nada de consultas periódicas. El ciclo principal
        # llama a aplicar() todo el tiempo con lo mismo.
        m = _Monitor(brillo=50)
        c = self.control(m)
        c.aplicar(80)
        m.llamadas = 0
        for _ in range(10):
            c.aplicar(80)
        self.assertEqual(m.llamadas, 0)

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

    def test_cambio_manual_sin_regla_se_usa_cuando_empieza_la_regla(self):
        m = _Monitor(brillo=50)
        c = self.control(m)
        c.aplicar(None)
        m.tocar_botones(90)                  # nadie se entera todavía: no se consulta
        c.aplicar(50)                        # empieza una regla: momento clave
        self.assertEqual(m.brillo, 45, '50 % del brillo real (90)')
        self.assertEqual(self.referencia(), 90)

    def test_cambio_manual_durante_una_regla_queda_como_brillo_real_al_terminar(self):
        m = _Monitor(brillo=50)
        c = self.control(m)
        c.aplicar(80)                        # regla: 40
        m.tocar_botones(70)
        c.aplicar(None)                      # terminó la regla
        self.assertEqual(m.brillo, 70, 'no se pisa lo que puso la persona')
        self.assertEqual(self.referencia(), 70)

    def test_cambio_manual_durante_una_regla_y_el_cms_cambia_el_nivel_manda_el_cms(self):
        m = _Monitor(brillo=50)
        c = self.control(m)
        c.aplicar(80)                        # regla: 40
        m.tocar_botones(70)
        c.aplicar(60)                        # el CMS cambió el nivel
        self.assertEqual(m.brillo, 30, '60 % de la referencia, que no cambió')
        self.assertEqual(self.referencia(), 50)

    def test_con_aviso_0x02_no_se_lee_el_brillo_si_nadie_toco_nada(self):
        m = _Monitor(brillo=50, aviso_02=True)
        c = self.control(m)
        c.aplicar(None)
        m.lecturas_brillo = 0
        c.aplicar(50)
        self.assertEqual(m.lecturas_brillo, 1, 'sólo la lectura de verificación después de escribir')

    def test_con_aviso_0x02_se_lee_y_se_baja_cuando_alguien_toco(self):
        m = _Monitor(brillo=50, aviso_02=True)
        c = self.control(m)
        c.aplicar(None)
        m.tocar_botones(60)
        c.aplicar(50)
        self.assertEqual(m.brillo, 30, '50 % de 60')
        self.assertFalse(m.cambiado, 'se bajó el aviso para enterarse del próximo cambio')

    def test_sin_aviso_0x02_se_lee_el_brillo_en_el_momento_clave(self):
        m = _Monitor(brillo=50, aviso_02=False)
        c = self.control(m)
        c.aplicar(None)
        m.lecturas_brillo = 0
        c.aplicar(50)
        self.assertEqual(m.lecturas_brillo, 2, 'antes de escribir y la verificación')

    def test_cambio_con_el_servicio_apagado_se_toma_al_arrancar(self):
        m = _Monitor(brillo=50)
        self.control(m).aplicar(None)
        m.brillo = 75                        # lo tocaron con la Pi apagada
        c = self.control(m)
        c.aplicar(100)
        self.assertEqual(m.brillo, 75, '100 % de la nueva referencia')

    def test_si_el_monitor_no_obedece_se_informa_y_se_reintenta(self):
        m = _Monitor(brillo=50, ignora_escritura=True)
        c = self.control(m)
        c.aplicar(50)
        self.assertTrue(any('quedó en 50' in p for p in c.diagnostico(True)))
        m.ignora_escritura = False
        c.aplicar(50)                        # mismo pedido, pero había fallado: se reintenta
        self.assertEqual(m.brillo, 25)
        self.assertFalse(any('quedó en' in p for p in c.diagnostico(True)))


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
