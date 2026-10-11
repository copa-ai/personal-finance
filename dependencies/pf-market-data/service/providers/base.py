"""Base común de los proveedores: limitador, cooldown y utilidades de parseo."""

import math
import time
from datetime import datetime, timezone
from typing import Any, Callable, Dict, List, Optional, Tuple

from errors import NotFound, ProviderError, ProviderUnavailable, RateLimited
from limits import ProviderState, TokenBucket
from netclient import HttpClient, Response

# Duraciones aproximadas de cada `range` (None = todo el histórico).
RANGE_SECONDS = {
    "1d": 86400, "5d": 5 * 86400, "1mo": 31 * 86400, "3mo": 92 * 86400,
    "6mo": 183 * 86400, "1y": 366 * 86400, "5y": 1827 * 86400, "max": None,
}
INTERVAL_SECONDS = {"1h": 3600, "1d": 86400, "1wk": 7 * 86400, "1mo": 31 * 86400}

# Algunas bolsas cotizan en la subunidad (peniques, centavos). Se normaliza a la
# divisa ISO dividiendo entre 100 para que el cliente no tenga que saberlo.
SUBUNITS = {"GBp": "GBP", "GBX": "GBP", "ZAc": "ZAR", "ZAC": "ZAR", "ILA": "ILS"}


def iso(ts: float) -> str:
    return datetime.fromtimestamp(ts, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def num(value: Any) -> Optional[float]:
    """float finito o None (acepta números y cadenas numéricas; nunca bool)."""
    if value is None or isinstance(value, bool):
        return None
    try:
        f = float(value)
    except (TypeError, ValueError):
        return None
    return f if math.isfinite(f) else None


def normalize_currency(currency: Optional[str]) -> Tuple[Optional[str], float]:
    """(divisa ISO, factor por el que multiplicar los precios)."""
    if currency in SUBUNITS:
        return SUBUNITS[currency], 0.01
    return (currency.upper() if isinstance(currency, str) and currency else None), 1.0


def scale(value: Optional[float], factor: float) -> Optional[float]:
    if value is None or factor == 1.0:
        return value
    return float(f"{value * factor:.10g}")


def make_quote(source: str, price: Any, currency: Optional[str], *, name: Optional[str] = None,
               previous_close: Any = None, as_of: Optional[float] = None,
               clock: Callable[[], float] = time.time) -> Dict[str, Any]:
    price = num(price)
    if price is None or price <= 0:
        raise ProviderError(f"{source}: precio no válido")
    prev = num(previous_close)
    if prev is not None and prev <= 0:
        prev = None
    currency, factor = normalize_currency(currency)
    return {
        "name": name or None,
        "price": scale(price, factor),
        "currency": currency,
        "previous_close": scale(prev, factor),
        "as_of": iso(as_of if as_of else clock()),
        "source": source,
    }


def candle(t: float, o: Any, h: Any, l: Any, c: Any, v: Any, factor: float = 1.0) -> Optional[dict]:
    c = num(c)
    if c is None:
        return None
    o, h, l = (num(x) for x in (o, h, l))
    return {
        "t": iso(t),
        "o": scale(o if o is not None else c, factor),
        "h": scale(h if h is not None else c, factor),
        "l": scale(l if l is not None else c, factor),
        "c": scale(c, factor),
        "v": num(v),
    }


class Provider:
    name = "base"
    per_minute = 30
    # False cuando falta la clave de API: el proveedor se salta en silencio.
    enabled = True

    def __init__(self, client: HttpClient, cache=None, clock: Callable[[], float] = time.time,
                 mono: Callable[[], float] = time.monotonic, per_minute: Optional[float] = None):
        self.client = client
        self.cache = cache
        self.clock = clock
        self.state = ProviderState(clock)
        self.bucket = TokenBucket(per_minute or self.per_minute, mono)

    # -- peticiones -------------------------------------------------------------
    def _request(self, url: str, headers: Optional[dict] = None, method: str = "GET",
                 body: Optional[bytes] = None) -> Response:
        if self.state.in_cooldown():
            raise ProviderUnavailable(f"{self.name}: en cooldown")
        if not self.bucket.try_acquire():
            raise ProviderUnavailable(f"{self.name}: límite local de peticiones")
        try:
            resp = self.client.request(method, url, headers, body)
        except ProviderError:
            self.state.failure()
            raise
        if resp.status == 429:
            self.state.rate_limited()
            raise RateLimited(f"{self.name}: HTTP 429")
        return resp

    def _json(self, url: str, headers: Optional[dict] = None, not_found=(404,)) -> Any:
        resp = self._request(url, headers)
        if resp.status in not_found:
            self.state.success()
            raise NotFound(f"{self.name}: no encontrado")
        if not 200 <= resp.status < 300:
            self.state.failure()
            raise ProviderError(f"{self.name}: HTTP {resp.status}")
        try:
            data = resp.json()
        except ProviderError:
            self.state.failure()
            raise
        self.state.success()
        return data

    # -- interfaz ---------------------------------------------------------------
    def quote(self, sym) -> Dict[str, Any]:
        raise ProviderUnavailable(f"{self.name}: no da cotizaciones de {sym.kind}")

    def quotes(self, syms: List) -> Tuple[Dict[str, dict], Dict[str, Exception]]:
        """Por defecto, una petición por símbolo. Los proveedores con lote lo sobrescriben."""
        found, errors = {}, {}
        for sym in syms:
            try:
                found[sym.canonical] = self.quote(sym)
            except ProviderError as e:
                errors[sym.canonical] = e
        return found, errors

    def history(self, sym, rng: str, interval: str) -> Dict[str, Any]:
        raise ProviderUnavailable(f"{self.name}: no da históricos de {sym.kind}")

    def status(self) -> str:
        return self.state.status()
