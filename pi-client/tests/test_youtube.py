"""
Videos de YouTube (spui/youtube.py): la Pi los baja con yt-dlp y los muestra
como un video local. VLC no abría el link (28/10/2026).

No se descarga nada de internet: el yt-dlp es un script falso que deja el
archivo donde lo dejaría el real, o falla con el mensaje que da YouTube.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import tempfile
import time
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui import youtube  # noqa: E402
from spui.youtube import DescargasYoutube, de_sync, id_de  # noqa: E402

ID = 'aqz-KE-bpKQ'
URL = f'https://www.youtube.com/watch?v={ID}'


class _Resultado:
    def __init__(self, codigo=0, stderr=''):
        self.returncode, self.stdout, self.stderr = codigo, '', stderr


class IdTest(unittest.TestCase):
    def test_reconoce_el_id(self):
        for url in (URL, f'https://youtu.be/{ID}?si=x', f'https://www.youtube.com/shorts/{ID}'):
            self.assertEqual(id_de(url), ID)
        self.assertIsNone(id_de('https://vimeo.com/1'))

    def test_de_sync_toma_los_youtube_de_la_playlist(self):
        sync = {'pantallas': [{'playlist': {'items': [
            {'contenido': {'tipo': 'imagen', 'titulo': 'Foto'}},
            {'contenido': {'tipo': 'youtube', 'titulo': 'Video', 'contenido_texto': URL}},
        ]}}]}
        self.assertEqual(de_sync(sync), [(URL, 'Video')])


class DescargasTest(unittest.TestCase):
    def setUp(self):
        self.media = tempfile.mkdtemp()
        self.exe = os.path.join(self.media, 'yt-dlp-falso')
        open(self.exe, 'w').close()
        self.d = DescargasYoutube(self.media, ejecutable=self.exe)

    def correr(self, efecto):
        """Procesa la cola en este hilo, con subprocess.run reemplazado."""
        with mock.patch.object(youtube.subprocess, 'run', side_effect=efecto) as run:
            while not self.d._cola.empty():
                id_video, url, titulo = self.d._cola.get()
                self.d._descargar(id_video, url, titulo)
                self.d._pendientes.discard(id_video)
        return run

    def test_baja_y_deja_el_archivo_con_su_nombre_final(self):
        def baja(cmd, **_):
            destino = os.path.dirname(cmd[cmd.index('-o') + 1])
            open(os.path.join(destino, f'{ID}.mp4'), 'wb').write(b'video')
            return _Resultado()
        self.d.pedir([(URL, 'Video')])
        self.correr(baja)
        self.assertEqual(self.d.ruta(URL), os.path.join(self.media, f'youtube-{ID}.mp4'))
        self.assertEqual(self.d.problemas(), [])

    def test_pide_el_formato_que_la_pi_reproduce_fluido(self):
        cmd = self.d.comando(URL, '/tmp/x')
        formato = cmd[cmd.index('-f') + 1]
        self.assertIn('avc1', formato)
        self.assertIn('height<=1080', formato)
        self.assertIn('fps<=30', formato)
        self.assertEqual(cmd[cmd.index('--merge-output-format') + 1], 'mp4')

    def test_con_solo_node_se_le_pide_a_yt_dlp(self):
        with mock.patch.object(youtube.shutil, 'which', side_effect=lambda p: '/usr/bin/node' if p == 'node' else None):
            self.assertIn('node', self.d.comando(URL, '/tmp/x'))
        with mock.patch.object(youtube.shutil, 'which', side_effect=lambda p: '/usr/bin/' + p):
            self.assertNotIn('--js-runtimes', self.d.comando(URL, '/tmp/x'))

    def test_pedir_dos_veces_no_duplica_ni_repite_lo_ya_bajado(self):
        self.d.pedir([(URL, 'Video')])
        self.d.pedir([(URL, 'Video')])
        self.assertEqual(self.d._cola.qsize(), 1)
        open(os.path.join(self.media, f'youtube-{ID}.mp4'), 'wb').close()
        self.d._pendientes.clear()
        self.d._cola = type(self.d._cola)()
        self.d.pedir([(URL, 'Video')])
        self.assertEqual(self.d._cola.qsize(), 0)

    def test_una_falla_se_informa_en_criollo_y_espera_antes_de_reintentar(self):
        self.d.pedir([(URL, 'Video')])
        self.correr(lambda cmd, **_: _Resultado(1, 'ERROR: [youtube] x: This video is unavailable'))
        self.assertIsNone(self.d.ruta(URL))
        self.assertIn('no está disponible', self.d.problemas()[0])
        self.d.pedir([(URL, 'Video')])
        self.assertEqual(self.d._cola.qsize(), 0, 'no se reintenta en el acto')

    def test_sin_yt_dlp_lo_dice(self):
        d = DescargasYoutube(self.media, ejecutable='/no/existe/yt-dlp')
        with mock.patch.object(youtube.shutil, 'which', return_value=None):
            d._descargar(ID, URL, 'Video')
        self.assertIn('yt-dlp no está instalado', d.problemas()[0])


class PlayerYoutubeTest(unittest.TestCase):
    def test_se_saltea_mientras_no_esta_bajado_y_despues_se_ve_como_video(self):
        from spui.player import Player
        p = Player.__new__(Player)
        p._youtube = mock.Mock(ruta=mock.Mock(return_value=None))
        p._youtube_avisados = set()
        p._reproducir_video = mock.Mock()
        p._reproducir_youtube(URL, None)
        p._reproducir_video.assert_not_called()
        p._youtube.ruta.return_value = '/cache/youtube-x.mp4'
        p._reproducir_youtube(URL, None)
        p._reproducir_video.assert_called_once_with('/cache/youtube-x.mp4', None)


if __name__ == '__main__':
    unittest.main()
