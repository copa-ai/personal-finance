"""Limitador por proveedor (token bucket) y estado de salud con cooldown exponencial."""

import threading
import time
from typing import Callable

COOLDOWN_START = 60.0
COOLDOWN_MAX = 30 * 60.0


class TokenBucket:
    """`per_minute` peticiones por minuto, con ráfaga de hasta `per_minute`. No bloquea."""

    def __init__(self, per_minute: float, clock: Callable[[], float] = time.monotonic):
        self.rate = per_minute / 60.0
        self.capacity = float(per_minute)
        self.tokens = float(per_minute)
        self.clock = clock
        self.updated = clock()
        self._lock = threading.Lock()

    def try_acquire(self) -> bool:
        with self._lock:
            now = self.clock()
            self.tokens = min(self.capacity, self.tokens + (now - self.updated) * self.rate)
            self.updated = now
            if self.tokens >= 1.0:
                self.tokens -= 1.0
                return True
            return False


class ProviderState:
    """Salud de un proveedor: ok | cooldown | error.

    Un 429 abre un cooldown que empieza en 60 s y se duplica en cada 429 seguido,
    hasta 30 min. Una respuesta correcta lo reinicia.
    """

    def __init__(self, clock: Callable[[], float] = time.time):
        self.clock = clock
        self.cooldown_until = 0.0
        self.backoff = COOLDOWN_START
        self.last = "ok"
        self._lock = threading.Lock()

    def in_cooldown(self) -> bool:
        with self._lock:
            return self.clock() < self.cooldown_until

    def rate_limited(self) -> None:
        with self._lock:
            self.cooldown_until = self.clock() + self.backoff
            self.backoff = min(self.backoff * 2, COOLDOWN_MAX)
            self.last = "cooldown"

    def success(self) -> None:
        with self._lock:
            self.backoff = COOLDOWN_START
            self.last = "ok"

    def failure(self) -> None:
        with self._lock:
            self.last = "error"

    def status(self) -> str:
        with self._lock:
            if self.clock() < self.cooldown_until:
                return "cooldown"
            return "error" if self.last == "error" else "ok"
