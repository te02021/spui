"""
Cliente REST para comunicación con el CMS SPUI.

Responsabilidades:
  - POST /reproductores/sync      → obtiene programación activa + alerta
  - POST /reproductores/heartbeat → confirma que el reproductor está vivo
  - GET  /media/{file}            → descarga archivos multimedia al caché local
"""

import hashlib
import logging
import os

import requests

logger = logging.getLogger(__name__)


class SyncClient:
    def __init__(self, api_url: str, api_key: str, media_dir: str):
        self._base = api_url.rstrip('/')
        self._media_dir = media_dir
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

    def descargar_media(self, url_descarga: str, hash_esperado: str | None = None) -> str:
        """
        Descarga un archivo de media desde su url_descarga (devuelta por el sync)
        y lo guarda en MEDIA_DIR. Si ya existe con el hash correcto, no lo descarga.
        Retorna la ruta local del archivo.
        """
        filename = url_descarga.rstrip('/').split('/')[-1]
        local    = os.path.join(self._media_dir, filename)

        if os.path.exists(local) and hash_esperado and self._hash_ok(local, hash_esperado):
            logger.debug('Media ya cacheada: %s', filename)
            return local

        logger.info('Descargando media: %s', filename)

        resp = self._session.get(url_descarga, timeout=60, stream=True)
        resp.raise_for_status()

        os.makedirs(self._media_dir, exist_ok=True)
        with open(local, 'wb') as fh:
            for chunk in resp.iter_content(chunk_size=8192):
                fh.write(chunk)

        if hash_esperado and not self._hash_ok(local, hash_esperado):
            os.remove(local)
            raise ValueError(f'Hash SHA-256 incorrecto para {filename}')

        return local

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
