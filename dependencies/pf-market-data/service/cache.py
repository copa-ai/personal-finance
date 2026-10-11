"""Caché clave-valor en SQLite (JSON), compartida por todos los hilos.

Las entradas no caducan al leerlas: el llamante decide con la edad si están frescas
o si solo sirven como dato `stale` cuando fallan todos los proveedores.
"""

import json
import logging
import os
import sqlite3
import threading
import time
from typing import Any, Callable, Optional, Tuple

import config

log = logging.getLogger("pf-market-data.cache")

# Lo que no se ha tocado en este tiempo se borra al arrancar.
PURGE_AFTER = 90 * 86400


def _open(path: str) -> sqlite3.Connection:
    if path != ":memory:":
        os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    conn = sqlite3.connect(path, check_same_thread=False, timeout=5)
    conn.execute("CREATE TABLE IF NOT EXISTS kv ("
                 "k TEXT PRIMARY KEY, v TEXT NOT NULL, stored_at REAL NOT NULL)")
    if path != ":memory:":
        conn.execute("PRAGMA journal_mode=WAL")
    conn.commit()
    return conn


class Cache:
    def __init__(self, path: str, clock: Callable[[], float] = time.time):
        self.clock = clock
        self._lock = threading.Lock()
        self.path = path
        for candidate in (path, config.fallback_cache_path(), ":memory:"):
            try:
                self._conn = _open(candidate)
                self.path = candidate
                break
            except (OSError, sqlite3.Error) as e:
                log.warning("caché no utilizable en %s: %s", candidate, e)
        if self.path != path:
            log.warning("caché en %s (MD_CACHE_PATH no escribible)", self.path)
        self.purge()

    def get(self, key: str) -> Optional[Tuple[Any, float]]:
        """(valor, edad en segundos) o None."""
        with self._lock:
            row = self._conn.execute("SELECT v, stored_at FROM kv WHERE k = ?", (key,)).fetchone()
        if row is None:
            return None
        return json.loads(row[0]), max(0.0, self.clock() - row[1])

    def get_fresh(self, key: str, ttl: float) -> Optional[Any]:
        hit = self.get(key)
        if hit is not None and hit[1] < ttl:
            return hit[0]
        return None

    def set(self, key: str, value: Any) -> None:
        data = json.dumps(value, separators=(",", ":"))
        with self._lock:
            self._conn.execute("INSERT OR REPLACE INTO kv (k, v, stored_at) VALUES (?, ?, ?)",
                               (key, data, self.clock()))
            self._conn.commit()

    def purge(self) -> None:
        with self._lock:
            self._conn.execute("DELETE FROM kv WHERE stored_at < ?", (self.clock() - PURGE_AFTER,))
            self._conn.commit()

    def close(self) -> None:
        with self._lock:
            self._conn.close()

    def __del__(self):
        try:
            self._conn.close()
        except Exception:  # noqa: BLE001 - en la destrucción no hay a quién avisar
            pass
