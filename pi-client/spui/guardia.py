"""
Guardia contra contenidos que cierran el reproductor.

Un archivo que VLC no aguanta puede matar el proceso con un error nativo
("free(): double free detected", 24/10/2026: una foto de 4608×3456 girada por
EXIF). Python no lo puede atrapar: systemd reinicia el servicio, el
reproductor vuelve a mostrar el mismo contenido, se vuelve a cerrar, y la
pantalla queda en un bucle de reinicios que además agota el límite de syncs.

Cómo se corta el bucle: antes de mostrar cada contenido se anota cuál es
(spui_en_curso.json, en memoria: ver _DIR_MEMORIA) y al terminarlo se borra la nota.
Si al arrancar la nota sigue ahí, el proceso murió mostrando ese contenido.
Con dos muertes seguidas en el mismo, se saltea, y el CMS lo ve en el
diagnóstico del reproductor.

La clave incluye el hash del archivo: si se vuelve a subir el contenido (otro
archivo, otro hash) se intenta de nuevo. Un deploy (otra versión del cliente)
también limpia la lista, por si el arreglo estaba en el reproductor.
"""

import json
import logging
import os
from typing import Optional

logger = logging.getLogger(__name__)

# Muertes seguidas mostrando el mismo contenido antes de saltearlo. Con una
# sola podría ser un reinicio normal (un deploy, un corte de luz) que cayó
# justo en ese contenido.
MUERTES_PARA_SALTEAR = 2


# La nota de "qué se está mostrando" va en memoria (tmpfs), no en la SD: se
# escribe en cada contenido, cada pocos segundos, y la tarjeta se gasta. En
# /dev/shm sobrevive a que el proceso muera, que es justo lo que se quiere
# detectar, y desaparece al reiniciar la Pi: un corte de luz no es culpa de
# ningún contenido y no tiene que contar.
_DIR_MEMORIA = '/dev/shm'


class GuardiaContenido:
    def __init__(self, directorio: str, version: str = '', dir_nota: Optional[str] = None):
        if dir_nota is None:
            dir_nota = _DIR_MEMORIA if os.path.isdir(_DIR_MEMORIA) else directorio
        self._en_curso = os.path.join(dir_nota, 'spui_en_curso.json')
        self._registro = os.path.join(directorio, 'contenidos_problematicos.json')
        self._version = version
        self._datos = self._leer(self._registro) or {}
        if self._datos.get('version') != version:
            self._datos = {'version': version, 'muertes': {}, 'salteados': {}}
        self._datos.setdefault('muertes', {})
        self._datos.setdefault('salteados', {})
        self._revisar_muerte_anterior()

    @staticmethod
    def clave(contenido: dict) -> str:
        return f"{contenido.get('id')}:{contenido.get('hash_archivo') or contenido.get('contenido_texto') or ''}"

    def salteado(self, contenido: dict) -> bool:
        return self.clave(contenido) in self._datos['salteados']

    def empezar(self, contenido: dict) -> None:
        self._escribir(self._en_curso, {'clave': self.clave(contenido), 'titulo': contenido.get('titulo') or ''})

    def terminar(self, contenido: dict) -> None:
        """El contenido se mostró sin que el proceso muriera: se borra la nota y su cuenta."""
        try:
            os.remove(self._en_curso)
        except FileNotFoundError:
            pass
        except OSError as exc:
            logger.warning('No se pudo borrar %s: %s', self._en_curso, exc)
        if self._datos['muertes'].pop(self.clave(contenido), None) is not None:
            self._guardar()

    def problemas(self) -> list[str]:
        return [
            f'El contenido «{titulo}» hizo que el reproductor se cerrara {MUERTES_PARA_SALTEAR} veces seguidas '
            'y se está salteando. Reemplazá el archivo (volvé a subirlo desde el CMS).'
            for titulo in self._datos['salteados'].values()
        ]

    # ── Interno ────────────────────────────────────────────────────────────

    def _revisar_muerte_anterior(self) -> None:
        nota = self._leer(self._en_curso)
        if not nota:
            return
        clave, titulo = nota.get('clave'), nota.get('titulo') or ''
        try:
            os.remove(self._en_curso)
        except OSError:
            pass
        if not clave:
            return
        muertes = self._datos['muertes'].get(clave, 0) + 1
        self._datos['muertes'][clave] = muertes
        logger.warning('El reproductor se cerró mostrando «%s» (%d vez/veces seguidas).', titulo, muertes)
        if muertes >= MUERTES_PARA_SALTEAR:
            self._datos['salteados'][clave] = titulo
            logger.error('«%s» se saltea: cerró el reproductor %d veces seguidas.', titulo, muertes)
        self._guardar()

    def _guardar(self) -> None:
        self._escribir(self._registro, self._datos)

    @staticmethod
    def _leer(ruta: str) -> Optional[dict]:
        try:
            with open(ruta, encoding='utf-8') as fh:
                datos = json.load(fh)
            return datos if isinstance(datos, dict) else None
        except (OSError, ValueError):
            return None

    @staticmethod
    def _escribir(ruta: str, datos: dict) -> None:
        # Reemplazo atómico: nunca queda un JSON a medio escribir.
        tmp = ruta + '.tmp'
        try:
            with open(tmp, 'w', encoding='utf-8') as fh:
                json.dump(datos, fh, ensure_ascii=False)
            os.replace(tmp, ruta)
        except OSError as exc:
            logger.warning('No se pudo escribir %s: %s', ruta, exc)
