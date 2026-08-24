"""
Caché local SQLite para modo offline (fail-safe).

Guarda el último sync exitoso. Si la red cae, el player sigue funcionando
con los datos del último estado conocido.

Alcance de este módulo: SÓLO la respuesta del sync (JSON).

Los archivos multimedia NO se indexan acá: viven en MEDIA_DIR y se resuelven
directamente contra el filesystem (`Player._resolver_local`), verificando su
integridad por SHA-256 al descargarlos (`SyncClient.descargar_media`). Tener
además un índice en SQLite duplicaría el estado y podría desincronizarse del
contenido real del disco.
"""

import json
import logging
import os
import sqlite3
from datetime import datetime, timezone

logger = logging.getLogger(__name__)

_SCHEMA = """
CREATE TABLE IF NOT EXISTS sync_data (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
"""


class Cache:
    def __init__(self, db_path: str):
        # dirname() devuelve '' si db_path es un nombre suelto (pasa si
        # SPUI_CACHE_DIR quedó vacío en spui.env); makedirs('') es un error.
        directorio = os.path.dirname(os.path.abspath(db_path))
        os.makedirs(directorio, exist_ok=True)

        try:
            self._conn = sqlite3.connect(db_path, check_same_thread=False)
            self._conn.executescript(_SCHEMA)
            self._conn.commit()
        except sqlite3.DatabaseError as exc:
            # El archivo existe pero no es una base válida (típico tras un corte
            # de luz). Se descarta y se arranca de cero: el caché es material
            # reconstruible, no vale la pena morir por él.
            logger.error('Caché SQLite ilegible (%s) — se recrea desde cero.', exc)
            try:
                self._conn.close()
            except Exception:
                pass
            os.replace(db_path, db_path + '.corrupta')
            self._conn = sqlite3.connect(db_path, check_same_thread=False)
            self._conn.executescript(_SCHEMA)
            self._conn.commit()

        logger.debug('SQLite caché abierta: %s', db_path)

    # ── Sync data ─────────────────────────────────────────────────────────

    def guardar_sync(self, data: dict) -> None:
        """
        Guarda el último sync. No propaga errores: si el disco está lleno o la
        base está bloqueada, se registra y se sigue con los datos en memoria —
        perder el caché es molesto, pero cortar la reproducción es peor.
        """
        now = datetime.now(timezone.utc).isoformat()
        try:
            self._conn.execute(
                "INSERT OR REPLACE INTO sync_data (key, value, updated_at) VALUES ('ultimo_sync', ?, ?)",
                (json.dumps(data), now),
            )
            self._conn.commit()
        except (sqlite3.Error, TypeError, ValueError) as exc:
            logger.warning('No se pudo guardar el caché de sync: %s', exc)

    def obtener_sync(self) -> dict | None:
        """
        Último sync guardado, o None si no hay nada utilizable.

        Nunca propaga una excepción: si lo hiciera, el reproductor entraría en
        un bucle del que no puede salir solo. Un corte de luz a mitad de una
        escritura —lo más común que le pasa a una Pi— deja la fila truncada;
        con json.loads() sin proteger, el proceso moría, systemd lo reiniciaba,
        volvía a leer la misma fila rota y moría otra vez. Ese bucle sobrevive
        a los reinicios y sólo se corta borrando el archivo a mano.

        Por eso, ante datos ilegibles se descarta la fila y se devuelve None:
        el reproductor muestra el fallback y se recupera solo en el próximo
        sync con red.
        """
        try:
            row = self._conn.execute(
                "SELECT value, updated_at FROM sync_data WHERE key = 'ultimo_sync'"
            ).fetchone()
        except sqlite3.Error as exc:
            logger.error('No se pudo leer el caché SQLite: %s', exc)
            return None

        if row is None:
            logger.warning('Sin datos en caché SQLite.')
            return None

        try:
            data = json.loads(row[0])
        except (json.JSONDecodeError, TypeError) as exc:
            logger.error(
                'Caché corrupta (%s) — se descarta. Se usará el fallback hasta '
                'el próximo sync con red.', exc,
            )
            self._borrar_sync()
            return None

        if not isinstance(data, dict):
            logger.error('Caché con formato inesperado (%s) — se descarta.', type(data).__name__)
            self._borrar_sync()
            return None

        logger.info('Usando caché de sync del %s', row[1])
        return data

    def _borrar_sync(self) -> None:
        """Elimina la entrada de caché inservible para no volver a leerla."""
        try:
            self._conn.execute("DELETE FROM sync_data WHERE key = 'ultimo_sync'")
            self._conn.commit()
        except sqlite3.Error as exc:
            logger.warning('No se pudo limpiar la caché corrupta: %s', exc)

    # ─────────────────────────────────────────────────────────────────────

    def close(self) -> None:
        self._conn.close()
