"""
Modo en vivo de la telemetría (29/10/2026): mientras alguien la mira en el
CMS, la Pi publica temperatura y RAM cada 1 s en spui/telemetria/vivo/{id},
además de la publicación normal de cada minuto. Vence solo si el CMS deja de
pedirlo.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import json
import os
import sys
import threading
import time
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui import telemetria as tel_mod  # noqa: E402
from spui.mqtt_listener import MqttListener  # noqa: E402
from spui.telemetria import TelemetriaPublisher  # noqa: E402


def _publisher(intervalo=60):
    p = TelemetriaPublisher('broker', 8883, interval=intervalo)
    p.set_reproductor_id(17)
    p._leer_temperatura = lambda: 55.5
    p._leer_ram = lambda: 40.0
    p._leer_disco = lambda: 1000
    return p


class VivoTest(unittest.TestCase):
    def test_sin_pedido_no_esta_en_vivo(self):
        self.assertFalse(_publisher().en_vivo())

    def test_el_pedido_dura_lo_pedido_y_tiene_tope(self):
        p = _publisher()
        p.vivo(120)
        self.assertTrue(p.en_vivo())
        self.assertLessEqual(p._vivo_hasta - time.monotonic(), 120)
        p.vivo(99999)
        self.assertLessEqual(p._vivo_hasta - time.monotonic(), tel_mod.VIVO_MAX_SEG)

    def test_un_valor_raro_no_rompe(self):
        p = _publisher()
        p.vivo('no es un número')
        p.vivo(None)
        self.assertTrue(p.en_vivo())

    def test_vence_solo(self):
        p = _publisher()
        p.vivo(1)   # el mínimo
        self.assertTrue(p.en_vivo())
        time.sleep(1.1)
        self.assertFalse(p.en_vivo())

    def test_publica_cada_segundo_en_su_topic_sin_tocar_el_normal(self):
        """Ciclo real (hilo corriendo): en vivo publica al topic vivo; el normal sigue a su ritmo."""
        p = _publisher(intervalo=60)
        cliente = mock.Mock()
        cliente.publish.return_value = mock.Mock(rc=0)
        with mock.patch.object(tel_mod, '_MQTT_OK', True), \
             mock.patch.object(tel_mod, 'nuevo_cliente_mqtt', return_value=cliente, create=True), \
             mock.patch.object(tel_mod, 'VIVO_INTERVALO_SEG', 0.05):
            p.start()
            time.sleep(0.1)
            p.vivo(60)
            time.sleep(0.4)
            p.detener()
            p.join(timeout=2)
        topics = [c.args[0] for c in cliente.publish.call_args_list]
        vivos = [t for t in topics if t == 'spui/telemetria/vivo/17']
        self.assertGreaterEqual(len(vivos), 3)
        self.assertNotIn('spui/telemetria/17', topics, 'la publicación normal es cada 60 s, no en cada muestra en vivo')
        datos = json.loads(cliente.publish.call_args_list[-1].args[1])
        self.assertEqual(datos['temperatura_soc_celsius'], 55.5)
        self.assertEqual(datos['uso_ram_porcentaje'], 40.0)


    def test_avisa_en_el_log_cuando_termina(self):
        """Sin esta línea no había forma de ver en el journal que la Pi volvió a cada minuto."""
        p = _publisher(intervalo=60)
        p._leer_disco_total = lambda: 30000
        cliente = mock.Mock()
        cliente.publish.return_value = mock.Mock(rc=0)
        with mock.patch.object(tel_mod, '_MQTT_OK', True), \
             mock.patch.object(tel_mod, 'nuevo_cliente_mqtt', return_value=cliente, create=True), \
             mock.patch.object(tel_mod, 'VIVO_INTERVALO_SEG', 0.05), \
             self.assertLogs('spui.telemetria', level='INFO') as log:
            p.start()
            time.sleep(0.1)
            p.vivo(1)
            time.sleep(1.4)
            p.detener()
            p.join(timeout=2)
        self.assertTrue(any('en vivo terminada' in l for l in log.output), log.output)
        datos = json.loads(cliente.publish.call_args_list[0].args[1])
        self.assertEqual(datos['espacio_disco_total_mb'], 30000)


class ComandoTest(unittest.TestCase):
    def test_el_listener_pasa_el_comando(self):
        recibido = threading.Event()
        listener = MqttListener('broker', 8883, on_alerta=mock.Mock(), on_desactivar=mock.Mock(),
                                on_telemetria_vivo=lambda pl: recibido.set() if pl.get('segundos') == 120 else None)
        msg = mock.Mock(payload=json.dumps({'accion': 'telemetria_vivo', 'segundos': 120}).encode(), topic='spui/comandos/reproductor/17')
        listener._on_message(None, None, msg)
        self.assertTrue(recibido.is_set())


if __name__ == '__main__':
    unittest.main()
