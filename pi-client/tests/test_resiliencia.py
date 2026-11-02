"""
Resiliencia del reproductor (noviembre 2026, decisiones de Mateo del 02/11):

  - Hora: la del CMS corrige la de la Pi (sin módulo RTC), sin tocar el
    reloj del sistema. Con la hora dudosa no se apaga por horario.
  - CEC: se escucha al TV (nunca se le pregunta). Si lo apagan con el control
    en horario, queda apagado hasta el próximo encendido del horario.
  - Encender por CEC: al arrancar y en cada encendido, con reintentos.
  - Cronograma con más de 24 h sin sincronizar: el pie dice de cuándo es.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import tempfile
import time
import unittest
from datetime import datetime
from importlib.util import find_spec
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui import cec  # noqa: E402
from spui.energia import GestorEnergia  # noqa: E402
from spui.reloj import Reloj  # noqa: E402
from test_energia import DENTRO, FUERA, _Pantalla, _Reloj, regla  # noqa: E402

_PIL = find_spec('PIL') is not None

# 02/11/2026 15:00 en Argentina (UTC-3) = 18:00 UTC.
CMS_TS = '2026-11-02T15:00:00-03:00'
CMS_EPOCH = datetime.fromisoformat(CMS_TS).timestamp()


class RelojTest(unittest.TestCase):
    def test_sin_dato_del_cms_usa_el_del_sistema_y_no_es_confiable_sin_ntp(self):
        r = Reloj(ahora_sistema=lambda: CMS_EPOCH, ntp_sincronizado=lambda: False)
        self.assertFalse(r.confiable())

    def test_ntp_sincronizado_es_confiable(self):
        r = Reloj(ahora_sistema=lambda: CMS_EPOCH, ntp_sincronizado=lambda: True)
        self.assertTrue(r.confiable())

    def test_la_hora_del_cms_corrige_un_reloj_atrasado_dos_dias(self):
        atrasado = CMS_EPOCH - 2 * 86400
        r = Reloj(ahora_sistema=lambda: atrasado, ntp_sincronizado=lambda: False)
        r.registrar_servidor(CMS_TS)
        self.assertTrue(r.confiable())
        self.assertEqual(r.ahora().replace(microsecond=0), datetime(2026, 11, 2, 15, 0))

    def test_la_zona_es_la_del_cms_no_la_de_la_pi(self):
        r = Reloj(ahora_sistema=lambda: CMS_EPOCH, ntp_sincronizado=lambda: False)
        r.registrar_servidor(CMS_TS)
        self.assertEqual(r.ahora().hour, 15)

    def test_descuenta_la_mitad_del_viaje(self):
        r = Reloj(ahora_sistema=lambda: CMS_EPOCH, ntp_sincronizado=lambda: False)
        r.registrar_servidor(CMS_TS, ida_y_vuelta=4.0)
        self.assertEqual(r.ahora().second, 2)

    def test_un_valor_ilegible_no_rompe_nada(self):
        r = Reloj(ahora_sistema=lambda: CMS_EPOCH, ntp_sincronizado=lambda: False)
        for malo in (None, '', 'ayer', '2026-11-02T15:00:00'):   # el último, sin zona
            r.registrar_servidor(malo)
        self.assertFalse(r.confiable())

    def test_el_sync_y_el_heartbeat_le_pasan_la_hora(self):
        from spui.sync import SyncClient
        r = mock.Mock()
        s = SyncClient('http://cms/api/spui', 'k', tempfile.mkdtemp(), reloj=r)
        resp = mock.Mock(json=lambda: {'servidor_ts': CMS_TS, 'pantallas': []}, raise_for_status=lambda: None)
        s._session = mock.Mock(post=mock.Mock(return_value=resp))
        s.sincronizar()
        s.heartbeat([])
        self.assertEqual([c.args[0] for c in r.registrar_servidor.call_args_list], [CMS_TS, CMS_TS])


class CecInterpretarTest(unittest.TestCase):
    def test_avisos_del_tv(self):
        self.assertEqual(cec.interpretar('TRAFFIC: [  2412]\t>> 0f:36'), 'standby')
        self.assertEqual(cec.interpretar('TRAFFIC: [  2412]\t>> 04:90:01'), 'standby')
        self.assertEqual(cec.interpretar('TRAFFIC: [  2412]\t>> 04:90:00'), 'on')
        self.assertEqual(cec.interpretar('TRAFFIC: [  2412]\t>> 0F:84:00:00:00'), 'on')

    def test_lo_demas_no_cuenta(self):
        self.assertIsNone(cec.interpretar('TRAFFIC: [  2412]\t<< 40:36'))      # lo que manda la Pi
        self.assertIsNone(cec.interpretar('TRAFFIC: [  2412]\t>> 4f:36'))      # otro equipo, no el TV
        self.assertIsNone(cec.interpretar('TRAFFIC: [  2412]\t>> 04'))         # un sondeo vacío
        self.assertIsNone(cec.interpretar('NOTICE: [  2412]\tCEC client registered'))
        self.assertIsNone(cec.interpretar('TRAFFIC: [  2412]\t>> 04:84:00:00:00'))  # no es a todos


class _CecFalso:
    """Lo que spui/cec.py hace con el TV, en memoria."""

    def __init__(self, al_avisar):
        self.al_avisar = al_avisar
        self.ordenes: list[str] = []

    def iniciar(self):
        return True

    def vivo(self):
        return True

    def enviar(self, orden):
        self.ordenes.append(orden)
        return True

    def detener(self):
        pass


class EnergiaResilienteTest(unittest.TestCase):
    def _gestor(self, cec_tv=True, espera=0.05):
        self.p = _Pantalla(cec=cec_tv)
        self.reloj = _Reloj()
        self.clientes: list[_CecFalso] = []

        def crear(al_avisar):
            c = _CecFalso(al_avisar)
            self.clientes.append(c)
            return c

        g = GestorEnergia(ejecutar=self.p.ejecutar, hay=self.p.hay, ruta_backlight=None,
                          firma_pantalla=lambda: 'una-pantalla',
                          ruta_estado_brillo=os.path.join(tempfile.mkdtemp(), 'brillo.json'),
                          reloj=self.reloj, entorno=self.p.entorno, crear_cec=crear,
                          espera_reintento=espera)
        self.addCleanup(g.detener)
        g.actualizar_reglas(regla())
        return g

    @property
    def ordenes(self):
        return self.clientes[-1].ordenes

    def test_con_la_hora_dudosa_no_apaga(self):
        g = self._gestor()
        g.aplicar(ahora=FUERA, hora_confiable=False)
        self.assertFalse(g.esta_apagada())
        g.aplicar(ahora=FUERA, hora_confiable=True)
        self.assertTrue(g.esta_apagada())

    def test_si_el_tv_responde_se_lo_escucha_y_se_le_manda_por_ahi(self):
        g = self._gestor()
        self.assertEqual(len(self.clientes), 1)
        g.aplicar(ahora=DENTRO)
        self.assertEqual(self.ordenes[0], 'on 0')

    def test_sin_cec_no_se_escucha(self):
        self._gestor(cec_tv=False)
        self.assertEqual(self.clientes, [])

    def test_al_encender_reintenta_dos_veces(self):
        g = self._gestor()
        g.aplicar(ahora=DENTRO)
        time.sleep(0.3)
        self.assertEqual(self.ordenes, ['on 0', 'on 0', 'on 0'])

    def test_apagado_con_el_control_queda_apagado_hasta_el_proximo_horario(self):
        g = self._gestor(espera=5)
        g.aplicar(ahora=DENTRO)
        self.clientes[-1].al_avisar('standby')
        self.assertTrue(any('se apagó con su control' in d for d in g.diagnostico()))
        # Una nueva detección de la pantalla no lo vuelve a encender.
        g._encendida = None
        g.aplicar(ahora=DENTRO)
        self.assertEqual(self.ordenes, ['on 0'])
        # Termina el horario y al día siguiente se enciende como siempre.
        g.aplicar(ahora=FUERA)
        g.aplicar(ahora=DENTRO)
        self.assertEqual(self.ordenes, ['on 0', 'standby 0', 'on 0'])
        self.assertFalse(any('se apagó con su control' in d for d in g.diagnostico()))

    def test_el_eco_de_nuestro_apagado_no_cuenta_como_manual(self):
        g = self._gestor(espera=5)
        g.aplicar(ahora=FUERA)
        g._encendida = True    # como si el horario recién hubiera vuelto
        self.clientes[-1].al_avisar('standby')
        self.assertFalse(any('se apagó con su control' in d for d in g.diagnostico()))

    def test_si_lo_encienden_con_el_control_vuelve_lo_normal(self):
        g = self._gestor(espera=5)
        g.aplicar(ahora=DENTRO)
        self.clientes[-1].al_avisar('standby')
        self.clientes[-1].al_avisar('on')
        self.assertFalse(any('se apagó con su control' in d for d in g.diagnostico()))

    def test_una_alerta_lo_enciende_aunque_lo_hayan_apagado(self):
        g = self._gestor(espera=5)
        g.aplicar(ahora=DENTRO)
        self.clientes[-1].al_avisar('standby')
        g.aplicar(ahora=DENTRO, hay_alerta=True)
        self.assertEqual(self.ordenes, ['on 0', 'on 0'])

    def test_apagarlo_a_mano_cancela_los_reintentos(self):
        g = self._gestor(espera=0.2)
        g.aplicar(ahora=DENTRO)
        self.clientes[-1].al_avisar('standby')
        time.sleep(0.6)
        self.assertEqual(self.ordenes, ['on 0'])


@unittest.skipUnless(_PIL, 'Pillow no instalado')
class CronogramaViejoTest(unittest.TestCase):
    def _player(self, actualizado, confiable=True):
        from spui.player import Player
        p = Player.__new__(Player)
        p._reloj = mock.Mock(ahora=lambda: datetime(2026, 11, 2, 15, 0), confiable=lambda: confiable)
        p.fijar_entorno('Campus E4', None, actualizado)
        return p

    def test_mas_de_24_h_sin_sincronizar_lo_dice(self):
        p = self._player('2026-10-31T18:40:00-03:00')
        self.assertEqual(p._actualizado_viejo(), datetime(2026, 10, 31, 18, 40))

    def test_reciente_o_con_hora_dudosa_no_dice_nada(self):
        self.assertIsNone(self._player('2026-11-02T14:00:00-03:00')._actualizado_viejo())
        self.assertIsNone(self._player('2026-10-31T18:40:00-03:00', confiable=False)._actualizado_viejo())

    def test_el_pie_del_cronograma_lo_muestra(self):
        from spui import tv
        c = {'tipo': 'cronograma', 'presentacion': {'plantilla': 'institucional', 'tema': 'claro'},
             'cronograma': {'edificio': 'Campus E4', 'items': []}}
        d = tv.Dibujante()
        with mock.patch.object(tv.Dibujante, '_escribir', autospec=True, side_effect=tv.Dibujante._escribir) as escribir:
            d.lamina(c, datetime(2026, 11, 2, 15, 0), actualizado=datetime(2026, 10, 31, 18, 40))
        textos = [c.args[5] for c in escribir.call_args_list]
        self.assertIn('Actualizado el 31/10 a las 18:40', textos)
        self.assertEqual(textos.count('Campus E4'), 1)   # sólo el título, no en el pie


if __name__ == '__main__':
    unittest.main()
