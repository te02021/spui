"""
Cliente REST para comunicación con el CMS SPUI.

Responsabilidades:
  - POST /reproductores/sync      → obtiene programación activa + alerta
  - POST /reproductores/heartbeat → confirma que el reproductor está vivo
  - GET  /media/{file}            → descarga archivos multimedia al caché local,
                                    verificados por SHA-256 antes de entrar
"""

import hashlib
import logging
import os
import threading

import requests

logger = logging.getLogger(__name__)


class SyncClient:
    def __init__(self, api_url: str, api_key: str, media_dir: str):
        self._base = api_url.rstrip('/')
        self._media_dir = media_dir
        # ruta → (tamaño, mtime_ns, hash) de los archivos ya verificados.
        self._validos: dict[str, tuple[int, int, str]] = {}
        self._lock_validos = threading.Lock()
        self._session = requests.Session()
        self._session.headers.update({
            'X-Api-Key': api_key,
            'Accept': 'application/json',
        })

    # ------------------------------------------------------------------ #

    def sincronizar(self) -> dict:
        """Llama a /reproductores/sync y devuelve el JSON completo."""
        resp = self._session.post(f'{self._base}/reproductores/sync', timeout=10)
        resp.raise_for_status()
        data = resp.json()
        pantallas = data.get('pantallas') or []
        primera   = pantallas[0] if pantallas else {}
        prog      = primera.get('programacion_activa')
        logger.info(
            'Sync OK — pantalla=%s programacion=%s alerta=%s',
            primera.get('nombre', '?'),
            prog.get('id') if prog else None,
            data.get('alerta_emergencia', {}).get('id') if data.get('alerta_emergencia') else None,
        )
        return data

    def heartbeat(self, diagnostico: list[str] | None = None) -> bool:
        """
        Registra que el reproductor está vivo. Retorna True si el servidor recibió.

        `diagnostico` son los problemas que el propio cliente detecta (por
        ejemplo, que no hay entorno gráfico y no puede mostrar nada). Viajan
        acá para que se vean en el panel del CMS: un equipo puede estar
        conectado y sincronizando y aun así tener la pantalla en negro, y eso
        no puede quedar sólo en el log local de la Pi.

        Se envía siempre la clave, incluso con la lista vacía: así el servidor
        sabe que el problema anterior se resolvió y limpia el aviso.
        """
        try:
            resp = self._session.post(
                f'{self._base}/reproductores/heartbeat',
                json={'diagnostico': diagnostico or []},
                timeout=5,
            )
            resp.raise_for_status()
            return True
        except Exception as exc:
            logger.warning('Heartbeat falló: %s', exc)
            return False

    def enviar_telemetria(self, metricas: dict) -> bool:
        """
        Envía telemetría por HTTP. Es la vía de respaldo de MQTT, no la principal.

        MQTT es el canal natural para métricas periódicas —fire-and-forget, con
        pérdida tolerable— y además es lo que se documenta en la tesis. Pero si
        el broker se cae, o el daemon que ingiere los mensajes no está corriendo,
        la telemetría desaparece sin que nadie se entere: los mensajes se
        publican contra un broker que no los reparte y no queda registro en
        ninguna parte.

        Este endpoint ya existía en el CMS y nadie lo usaba. Reusa la sesión con
        X-Api-Key, así que a diferencia de MQTT no necesita credencial aparte ni
        que el reproductor_id ya se conozca.

        El servidor exige uso_ram_porcentaje y espacio_disco_libre_mb; los otros
        dos campos son opcionales.
        """
        try:
            resp = self._session.post(
                f'{self._base}/reproductores/telemetria',
                json=metricas,
                timeout=5,
            )
            resp.raise_for_status()
            return True
        except Exception as exc:
            logger.warning('Telemetría por HTTP falló: %s', exc)
            return False

    def ruta_local(self, url_descarga: str) -> str:
        """Dónde queda en el caché el archivo de esta url_descarga."""
        return os.path.join(self._media_dir, url_descarga.rstrip('/').split('/')[-1])

    def descargar_media(self, url_descarga: str, hash_esperado: str | None = None) -> str:
        """
        Devuelve la ruta local de un archivo de media, descargándolo si hace falta.

        Si ya está en el caché y es válido (archivo_valido), no se descarga.
        Si no, se baja a un temporal `.part` y sólo se mueve al nombre final
        cuando llegó completo y su SHA-256 coincide: una descarga cortada a mitad
        de camino (corte de red, de luz) o un archivo distinto del esperado NUNCA
        quedan en el caché con el nombre que usa el player (CP07). El reemplazo es
        atómico (os.replace), así que el player nunca ve un archivo a medio escribir.

        Lanza excepción si no se pudo obtener un archivo válido.
        """
        local = self.ruta_local(url_descarga)
        nombre = os.path.basename(local)

        if self.archivo_valido(local, hash_esperado):
            logger.debug('Media ya cacheada: %s', nombre)
            return local

        logger.info('Descargando media: %s', nombre)
        os.makedirs(self._media_dir, exist_ok=True)
        # Nombre único por hilo: la alerta se resuelve en el hilo MQTT mientras
        # el principal puede estar haciendo el prefetch del mismo archivo.
        parcial = f'{local}.{os.getpid()}-{threading.get_ident()}{self.SUFIJO_PARCIAL}'
        try:
            # (conexión, lectura): sin CMS, el player no se traba 60 s por archivo.
            resp = self._session.get(url_descarga, timeout=(5, 60), stream=True)
            resp.raise_for_status()
            with open(parcial, 'wb') as fh:
                for chunk in resp.iter_content(chunk_size=8192):
                    fh.write(chunk)
                fh.flush()
                os.fsync(fh.fileno())  # que un corte de luz no deje el archivo vacío tras el replace

            if hash_esperado and not self._hash_ok(parcial, hash_esperado):
                raise ValueError(f'Hash SHA-256 incorrecto para {nombre}: se descarta la descarga')
            if os.path.getsize(parcial) == 0:
                raise ValueError(f'{nombre} llegó vacío: se descarta la descarga')

            os.replace(parcial, local)
        finally:
            if os.path.exists(parcial):
                os.remove(parcial)

        self._recordar_valido(local, hash_esperado)
        return local

    # ── Validación del caché ───────────────────────────────────────────── #

    SUFIJO_PARCIAL = '.part'

    def archivo_valido(self, ruta: str, hash_esperado: str | None) -> bool:
        """
        True si el archivo está en el caché y se puede reproducir.

        Con hash: su SHA-256 tiene que coincidir. Así un archivo alterado o
        dañado en disco (tarjeta SD, corte de luz) no se reproduce aunque no
        haya red para reemplazarlo. Si no coincide se borra del caché, para que
        el próximo sync lo vuelva a bajar.
        Sin hash (el CMS no lo informó): no hay contra qué comparar, se acepta
        si existe y no está vacío.

        El resultado se memoriza por (tamaño, fecha de modificación): verificar
        un video de cientos de MB en cada vuelta de la playlist no tiene sentido
        si el archivo no cambió.
        """
        try:
            st = os.stat(ruta)
        except OSError:
            return False
        if st.st_size == 0:
            return False
        if not hash_esperado:
            return True

        firma = (st.st_size, st.st_mtime_ns, hash_esperado)
        with self._lock_validos:
            if self._validos.get(ruta) == firma:
                return True

        if self._hash_ok(ruta, hash_esperado):
            with self._lock_validos:
                self._validos[ruta] = firma
            return True

        logger.warning('Archivo del caché alterado o incompleto (hash distinto): %s — se descarta.', os.path.basename(ruta))
        with self._lock_validos:
            self._validos.pop(ruta, None)
        try:
            os.remove(ruta)
        except OSError:
            pass
        return False

    def _recordar_valido(self, ruta: str, hash_esperado: str | None) -> None:
        if not hash_esperado:
            return
        try:
            st = os.stat(ruta)
        except OSError:
            return
        with self._lock_validos:
            self._validos[ruta] = (st.st_size, st.st_mtime_ns, hash_esperado)

    def limpiar_parciales(self) -> int:
        """
        Borra las descargas a medias que quedaron de una ejecución anterior
        (el proceso murió o se cortó la luz en plena descarga). Se llama al
        arrancar, antes de que haya descargas en curso. Devuelve cuántas borró.
        """
        borrados = 0
        try:
            nombres = os.listdir(self._media_dir)
        except OSError:
            return 0
        for nombre in nombres:
            if nombre.endswith(self.SUFIJO_PARCIAL):
                try:
                    os.remove(os.path.join(self._media_dir, nombre))
                    borrados += 1
                except OSError as exc:
                    logger.warning('No se pudo borrar la descarga a medias %s: %s', nombre, exc)
        if borrados:
            logger.info('Se borraron %d descarga(s) a medias de una ejecución anterior.', borrados)
        return borrados

    def prefetch_media(self, sync_data: dict) -> None:
        """
        Descarga proactivamente todos los archivos multimedia referenciados en
        el sync response mientras la red esté disponible.

        Esto asegura que cuando la red se caiga, el player tenga todos los
        archivos necesarios en el caché local y pueda seguir reproduciendo.

        Se llama después de cada sync exitoso. Ignora errores individuales
        (un archivo roto no impide cachear el resto).
        """
        urls = self._extraer_urls_media(sync_data)
        if not urls:
            return

        logger.info('Prefetch: %d archivo(s) a verificar/descargar.', len(urls))
        ok = 0
        for url, hash_esperado in urls:
            try:
                self.descargar_media(url, hash_esperado)
                ok += 1
            except Exception as exc:
                logger.warning('Prefetch falló para %s: %s', url.split('/')[-1], exc)

        logger.info('Prefetch completado: %d/%d archivos disponibles offline.', ok, len(urls))

    @staticmethod
    def _extraer_urls_media(sync_data: dict) -> list[tuple[str, str | None]]:
        """
        Recorre el sync_data y extrae todas las (url_descarga, hash_archivo)
        de contenidos con tipo imagen/video/qr.
        """
        urls: list[tuple[str, str | None]] = []
        tipos_con_archivo = {'imagen', 'video', 'qr'}

        for pantalla in sync_data.get('pantallas') or []:
            playlist = pantalla.get('playlist')
            if not playlist:
                continue
            for item in playlist.get('items', []):
                c = item.get('contenido', {})
                if c.get('tipo') in tipos_con_archivo and c.get('url_descarga'):
                    urls.append((c['url_descarga'], c.get('hash_archivo')))

        alerta = sync_data.get('alerta_emergencia')
        if alerta:
            c = alerta.get('contenido') or {}
            if c.get('tipo') in tipos_con_archivo and c.get('url_descarga'):
                urls.append((c['url_descarga'], c.get('hash_archivo')))

        return urls

    # ------------------------------------------------------------------ #

    @staticmethod
    def _hash_ok(path: str, expected: str) -> bool:
        sha = hashlib.sha256()
        with open(path, 'rb') as fh:
            for chunk in iter(lambda: fh.read(8192), b''):
                sha.update(chunk)
        return sha.hexdigest() == expected
