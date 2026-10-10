"""
Pruebas del caché de media del cliente: nada que no esté completo y verificado
llega a reproducirse (caso de prueba CP07 del informe).

No usan red ni pantalla: la sesión HTTP es falsa y el Player se crea sin pasar
por su constructor (que buscaría X11 y levantaría VLC encima del servicio).

Correr en la Pi, desde la carpeta del cliente:
    cd ~/pi-client && /opt/spui/venv/bin/python3 -m unittest discover -s tests -v
"""

import hashlib
import os
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

from spui.sync import SyncClient  # noqa: E402

CONTENIDO = b'contenido de prueba ' * 1000
HASH = hashlib.sha256(CONTENIDO).hexdigest()
URL = 'http://cms/api/spui/media/imagenes/foto.jpg'


class _Respuesta:
    def __init__(self, cuerpo: bytes, cortar_en: int | None = None):
        self._cuerpo = cuerpo
        self._cortar_en = cortar_en

    def raise_for_status(self):
        pass

    def iter_content(self, chunk_size=8192):
        enviados = 0
        for i in range(0, len(self._cuerpo), chunk_size):
            if self._cortar_en is not None and enviados >= self._cortar_en:
                raise ConnectionError('se cortó la red en plena descarga')
            trozo = self._cuerpo[i:i + chunk_size]
            enviados += len(trozo)
            yield trozo


class _Sesion:
    """Reemplaza a requests.Session: devuelve lo que se le indique, o falla como sin red."""

    def __init__(self):
        self.respuesta = None
        self.pedidos = 0

    def get(self, url, timeout=None, stream=False):
        self.pedidos += 1
        if self.respuesta is None:
            raise ConnectionError('sin red')
        return self.respuesta


class CacheDeMediaTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp(prefix='spui-test-')
        self.cliente = SyncClient('http://cms/api/spui', 'clave', self.dir)
        self.sesion = _Sesion()
        self.cliente._session = self.sesion
        self.local = os.path.join(self.dir, 'foto.jpg')

    def tearDown(self):
        shutil.rmtree(self.dir, ignore_errors=True)

    def _restos(self):
        return [n for n in os.listdir(self.dir) if n.endswith(SyncClient.SUFIJO_PARCIAL)]

    # ── descarga ──────────────────────────────────────────────────────────

    def test_descarga_completa_y_verificada_queda_en_el_cache(self):
        self.sesion.respuesta = _Respuesta(CONTENIDO)
        ruta = self.cliente.descargar_media(URL, HASH)
        self.assertEqual(ruta, self.local)
        with open(ruta, 'rb') as fh:
            self.assertEqual(fh.read(), CONTENIDO)
        self.assertEqual(self._restos(), [])

    def test_descarga_cortada_no_deja_nada_con_el_nombre_final(self):
        self.sesion.respuesta = _Respuesta(CONTENIDO, cortar_en=len(CONTENIDO) // 2)
        with self.assertRaises(ConnectionError):
            self.cliente.descargar_media(URL, HASH)
        self.assertFalse(os.path.exists(self.local), 'un archivo a medias quedó con el nombre que usa el player')
        self.assertEqual(self._restos(), [], 'quedó el temporal .part')

    def test_copia_con_otro_hash_no_se_usa_aunque_falle_la_descarga_nueva(self):
        # El CMS reemplazó el archivo (hash nuevo) y la Pi tiene el anterior.
        # No hay forma de distinguir "versión vieja" de "archivo alterado", así
        # que la copia que no coincide sale del caché y no se reproduce, aunque
        # la descarga de la nueva falle. El ítem se saltea hasta que haya red.
        with open(self.local, 'wb') as fh:
            fh.write(CONTENIDO)
        otro = b'version nueva ' * 500
        self.sesion.respuesta = _Respuesta(otro, cortar_en=100)
        with self.assertRaises(ConnectionError):
            self.cliente.descargar_media(URL, hashlib.sha256(otro).hexdigest())
        self.assertFalse(os.path.exists(self.local))
        self.assertEqual(self._restos(), [])

    def test_hash_incorrecto_se_rechaza(self):
        self.sesion.respuesta = _Respuesta(b'otra cosa, no lo que dice el CMS')
        with self.assertRaises(ValueError):
            self.cliente.descargar_media(URL, HASH)
        self.assertFalse(os.path.exists(self.local))
        self.assertEqual(self._restos(), [])

    def test_archivo_vacio_se_rechaza(self):
        self.sesion.respuesta = _Respuesta(b'')
        with self.assertRaises(ValueError):
            self.cliente.descargar_media(URL, None)
        self.assertFalse(os.path.exists(self.local))

    # ── caché ya descargado ───────────────────────────────────────────────

    def test_archivo_valido_no_se_vuelve_a_descargar(self):
        with open(self.local, 'wb') as fh:
            fh.write(CONTENIDO)
        self.assertEqual(self.cliente.descargar_media(URL, HASH), self.local)
        self.assertEqual(self.sesion.pedidos, 0)

    def test_archivo_alterado_en_disco_sin_red_no_se_reproduce(self):
        with open(self.local, 'wb') as fh:
            fh.write(CONTENIDO)
        self.assertTrue(self.cliente.archivo_valido(self.local, HASH))
        with open(self.local, 'ab') as fh:  # alguien (o la SD) lo modificó
            fh.write(b'inyectado')
        self.assertFalse(self.cliente.archivo_valido(self.local, HASH))
        self.assertFalse(os.path.exists(self.local), 'el archivo alterado tiene que salir del caché')
        with self.assertRaises(ConnectionError):  # sin red no hay reemplazo
            self.cliente.descargar_media(URL, HASH)

    def test_sin_hash_se_acepta_si_existe_y_no_esta_vacio(self):
        with open(self.local, 'wb') as fh:
            fh.write(CONTENIDO)
        self.assertTrue(self.cliente.archivo_valido(self.local, None))
        open(self.local, 'wb').close()
        self.assertFalse(self.cliente.archivo_valido(self.local, None))

    def test_limpiar_parciales_borra_descargas_a_medias(self):
        resto = os.path.join(self.dir, 'video.mp4.123-456' + SyncClient.SUFIJO_PARCIAL)
        with open(resto, 'wb') as fh:
            fh.write(b'a medias')
        with open(self.local, 'wb') as fh:
            fh.write(CONTENIDO)
        self.assertEqual(self.cliente.limpiar_parciales(), 1)
        self.assertFalse(os.path.exists(resto))
        self.assertTrue(os.path.exists(self.local), 'no tiene que tocar los archivos buenos')


class PlayerUsaSoloCopiasValidasTest(unittest.TestCase):
    """_resolver_local del Player: sin copia válida, el ítem se saltea (None)."""

    def setUp(self):
        from spui.player import Player
        self.dir = tempfile.mkdtemp(prefix='spui-test-')
        self.cliente = SyncClient('http://cms/api/spui', 'clave', self.dir)
        self.cliente._session = _Sesion()  # sin red
        self.player = Player.__new__(Player)  # sin constructor: no busca pantalla ni VLC
        self.player._media_dir = self.dir
        self.local = os.path.join(self.dir, 'foto.jpg')

    def tearDown(self):
        shutil.rmtree(self.dir, ignore_errors=True)

    def test_copia_valida_se_usa_sin_red(self):
        with open(self.local, 'wb') as fh:
            fh.write(CONTENIDO)
        self.assertEqual(self.player._resolver_local(URL, {'hash_archivo': HASH}, self.cliente), self.local)

    def test_copia_alterada_no_se_usa(self):
        with open(self.local, 'wb') as fh:
            fh.write(CONTENIDO + b'x')
        self.assertIsNone(self.player._resolver_local(URL, {'hash_archivo': HASH}, self.cliente))


if __name__ == '__main__':
    unittest.main()
