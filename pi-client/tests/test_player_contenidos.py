"""
Cómo reproduce el player los contenidos de una playlist (octubre de 2026):

  - Duración: la manda el CMS (PlaylistItem::getDuracionEfectiva). Un video
    sin duración en una rotación se ve completo, no 10 s.
  - Video: termina cuando VLC informa el final, no a reloj.
  - Texto y cronograma: se dibujan como imagen (antes, con el marq de VLC
    sobre blank://, no se veían en la Pi).

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import tempfile
import threading
import time
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui import player as player_mod  # noqa: E402
from spui.player import Player  # noqa: E402


def _player(media_dir: str = '') -> Player:
    """Un Player sin VLC ni pantalla: sólo lo que usan estos métodos."""
    p = Player.__new__(Player)
    p._media_dir = media_dir
    p._stop = mock.Mock(is_set=mock.Mock(return_value=False))
    p._tiene_alerta = mock.Mock(return_value=False)
    p._interrumpido = threading.Event()
    p._guardia = None
    p._ventana = None
    p._video_activo = False
    p._incrustado = False
    return p


def _item(tipo: str, duracion):
    return {'duracion_efectiva_seg': duracion, 'contenido': {'tipo': tipo}}


class DuracionTest(unittest.TestCase):
    def test_respeta_la_que_manda_el_cms(self):
        self.assertEqual(_player()._resolver_duracion(_item('video', 23), 3), 23)
        self.assertEqual(_player()._resolver_duracion(_item('imagen', 15), 3), 15)

    def test_permanente_unico_queda_fijo(self):
        self.assertIsNone(_player()._resolver_duracion(_item('imagen', None), 1))
        self.assertIsNone(_player()._resolver_duracion(_item('video', None), 1))

    def test_permanente_en_rotacion_dura_lo_de_rotacion(self):
        self.assertEqual(_player()._resolver_duracion(_item('texto', None), 3), Player._DURACION_POR_DEFECTO)

    def test_video_sin_duracion_en_rotacion_no_se_corta_a_los_10_s(self):
        self.assertEqual(_player()._resolver_duracion(_item('video', None), 3), Player._TOPE_VIDEO_SEG)


class FinDeVideoTest(unittest.TestCase):
    def setUp(self):
        # vlc puede no estar instalado en la PC: alcanza con los estados.
        self.estados = mock.Mock(Ended='ended', Error='error', Stopped='stopped', Playing='playing')
        parche = mock.patch.object(player_mod, 'vlc', mock.Mock(State=self.estados), create=True)
        parche.start()
        self.addCleanup(parche.stop)

    def test_termina_cuando_vlc_informa_el_final(self):
        p = _player()
        secuencia = iter(['playing', 'playing', 'ended'])
        p._media_player = mock.Mock(get_state=lambda: next(secuencia, 'ended'))
        inicio = time.monotonic()
        p._esperar_fin_video(60)
        self.assertLess(time.monotonic() - inicio, 2)

    def test_el_maximo_frena_si_vlc_nunca_avisa(self):
        p = _player()
        p._media_player = mock.Mock(get_state=lambda: 'playing')
        with mock.patch.object(Player, '_MARGEN_FIN_VIDEO_SEG', 0):
            inicio = time.monotonic()
            p._esperar_fin_video(1)
        self.assertLess(time.monotonic() - inicio, 2)

    def test_una_alerta_lo_corta(self):
        p = _player()
        p._tiene_alerta = mock.Mock(return_value=True)
        p._media_player = mock.Mock(get_state=lambda: 'playing')
        inicio = time.monotonic()
        p._esperar_fin_video(60)
        self.assertLess(time.monotonic() - inicio, 1)


@unittest.skipUnless(__import__('importlib').util.find_spec('PIL'), 'Pillow no instalado')
class TextoComoImagenTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.p = _player(self.dir)

    def test_dibuja_una_imagen_a_pantalla_completa(self):
        from PIL import Image
        ruta = self.p._imagen_de_texto('Inscripciones abiertas')
        self.assertTrue(ruta and os.path.exists(ruta))
        self.assertEqual(Image.open(ruta).size, (1920, 1080))

    def test_el_mismo_texto_reusa_la_imagen_y_otro_da_otra(self):
        a = self.p._imagen_de_texto('versión 1')
        self.assertEqual(a, self.p._imagen_de_texto('versión 1'))
        self.assertNotEqual(a, self.p._imagen_de_texto('versión 2'))
        self.assertNotEqual(a, self.p._imagen_de_texto('versión 1', titulo='Cronograma'))

    def test_un_texto_larguisimo_entra_igual(self):
        self.assertIsNotNone(self.p._imagen_de_texto('palabra ' * 400))

    def test_conserva_solo_las_ultimas(self):
        with mock.patch.object(Player, '_TEXTO_GUARDADAS', 3):
            for i in range(6):
                self.p._imagen_de_texto(f'texto {i}')
                time.sleep(0.01)   # mtimes distintos
        guardadas = [n for n in os.listdir(self.dir) if n.startswith(Player._TEXTO_PREFIJO)]
        self.assertEqual(len(guardadas), 3)

    def test_si_vlc_no_lo_muestra_no_se_espera_su_duracion(self):
        """Un contenido que VLC no abre dejaba la pantalla trabada toda su duración (28/10/2026)."""
        self.p._simulacion = False
        self.p._vlc = mock.Mock()
        self.p._reproducir_media = mock.Mock(return_value=False)
        self.p._esperar = mock.Mock()
        self.p._esperar_fin_video = mock.Mock()
        self.p._reproducir_video('/tmp/x.mp4', 23)
        self.p._reproducir_imagen('/tmp/x.jpg', 10)
        self.p._esperar.assert_not_called()
        self.p._esperar_fin_video.assert_not_called()

    def test_un_tipo_desconocido_se_saltea_sin_esperar(self):
        """Un 'youtube' viejo (el CMS ya no lo tiene) no puede trabar la playlist."""
        self.p._simulacion = False
        self.p._tipos_avisados = set()
        self.p._esperar = mock.Mock()
        self.p._reproducir_item({'contenido': {'tipo': 'youtube', 'titulo': 'YT'}}, None, 10)
        self.p._esperar.assert_not_called()
        self.assertIn('youtube', self.p._tipos_avisados)

    def test_el_texto_va_por_el_camino_de_imagen_no_por_marq(self):
        self.p._simulacion = False
        self.p._reproducir_imagen = mock.Mock()
        self.p._vlc = mock.Mock()
        self.p._reproducir_texto('Hola', 10)
        self.p._reproducir_imagen.assert_called_once()
        self.p._vlc.media_new.assert_not_called()


if __name__ == '__main__':
    unittest.main()
