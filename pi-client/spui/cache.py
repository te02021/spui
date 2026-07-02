"""
Caché local SQLite para modo offline (fail-safe).

Guarda el último sync exitoso. Si la red cae, el player sigue funcionando
con los datos y archivos multimedia del último estado conocido.

Fase 7 (offline avanzado) amplía este módulo con detección de conectividad
y estrategia de prefetch de archivos.
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

CREATE TABLE IF NOT EXISTS media_cache (
    filename      TEXT PRIMARY KEY,
    local_path    TEXT NOT NULL,
    hash_sha256   TEXT,
    downloaded_at TEXT NOT NULL
);
"""


class Cache:
    def __init__(self, db_path: str):
        os.makedirs(os.path.dirname(db_path), exist_ok=True)
        self._conn = sqlite3.connect(db_path, check_same_thread=False)
        self._conn.executescript(_SCHEMA)
        self._conn.commit()
        logger.debug('SQLite caché abierta: %s', db_path)

    # ── Sync data ─────────────────────────────────────────────────────────

    def guardar_sync(self, data: dict) -> None:
        now = datetime.now(timezone.utc).isoformat()
        self._conn.execute(
            "INSERT OR REPLACE INTO sync_data (key, value, updated_at) VALUES ('ultimo_sync', ?, ?)",
            (json.dumps(data), now),
        )
        self._conn.commit()

    def obtener_sync(self) -> dict | None:
        row = self._conn.execute(
            "SELECT value, updated_at FROM sync_data WHERE key = 'ultimo_sync'"
        ).fetchone()
        if row is None:
            logger.warning('Sin datos en caché SQLite.')
            return None
        data = json.loads(row[0])
        logger.info('Usando caché de sync del %s', row[1])
        return data

    # ── Media local ───────────────────────────────────────────────────────

    def registrar_media(self, filename: str, local_path: str, hash_sha256: str | None = None) -> None:
        now = datetime.now(timezone.utc).isoformat()
        self._conn.execute(
            "INSERT OR REPLACE INTO media_cache (filename, local_path, hash_sha256, downloaded_at) VALUES (?, ?, ?, ?)",
            (filename, local_path, hash_sha256, now),
        )
        self._conn.commit()

    def ruta_local(self, filename: str) -> str | None:
        row = self._conn.execute(
            "SELECT local_path FROM media_cache WHERE filename = ?", (filename,)
        ).fetchone()
        return row[0] if row else None

    # ─────────────────────────────────────────────────────────────────────

    def close(self) -> None:
        self._conn.close()
