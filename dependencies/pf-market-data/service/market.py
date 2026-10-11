"""Orquestador: cadenas de proveedores con respaldo, caché y datos `stale`.

Cada operación prueba los proveedores en orden y anota en `source` cuál respondió.
Si fallan todos, se sirve el último valor cacheado (cualquier antigüedad) marcado
como `stale`; si no hay, error.
"""

import logging
import re
import time
from concurrent.futures import ThreadPoolExecutor
from typing import Any, Dict, List, Optional, Tuple

import symbols
from errors import NotFound, ProviderError
from providers.base import Provider
from providers.binance import Binance
from providers.coingecko import CoinGecko
from providers.ecb import Ecb
from providers.keyed import AlphaVantage, Finnhub, TwelveData
from providers.kraken import Kraken
from providers.ntfy import PRIORITY, Ntfy
from providers.yahoo import Yahoo

log = logging.getLogger("pf-market-data.market")

MAX_SYMBOLS = 50
MAX_SEARCH_RESULTS = 20
HISTORY_TTL_DAILY = 6 * 3600
HISTORY_TTL_INTRADAY = 10 * 60
SEARCH_TTL = 24 * 3600

RANGES = ("1d", "5d", "1mo", "3mo", "6mo", "1y", "5y", "max")
INTERVALS = ("1h", "1d", "1wk", "1mo")
# Combinaciones admitidas (Yahoo no da 1h más allá de ~2 años; velas de 1wk/1mo
# en rangos cortos no tienen sentido).
VALID_COMBOS = {
    "1h": {"1d", "5d", "1mo", "3mo", "6mo", "1y"},
    "1d": set(RANGES),
    "1wk": {"1mo", "3mo", "6mo", "1y", "5y", "max"},
    "1mo": {"3mo", "6mo", "1y", "5y", "max"},
}

_TAG_RE = re.compile(r"[A-Za-z0-9_+\-]{1,40}")


class ServiceError(Exception):
    def __init__(self, message: str, status: int):
        super().__init__(message)
        self.message = message
        self.status = status


def failure_message(errors: List[Exception]) -> str:
    if not errors:
        return "sin proveedores para este símbolo"
    if all(isinstance(e, NotFound) for e in errors):
        return "no encontrado"
    return "proveedores no disponibles"


def finalize(canonical: str, q: Dict[str, Any]) -> Dict[str, Any]:
    price, prev = q["price"], q.get("previous_close")
    change = change_pct = None
    if prev:
        change = float(f"{price - prev:.10g}")
        change_pct = round((price - prev) / prev * 100, 4)
    return {
        "symbol": canonical, "name": q.get("name"), "price": price,
        "currency": q.get("currency"), "previous_close": prev, "change": change,
        "change_pct": change_pct, "as_of": q["as_of"], "source": q["source"], "stale": False,
    }


def _has_control(text: str) -> bool:
    return any(ord(c) < 0x20 or ord(c) == 0x7F for c in text)


class Market:
    def __init__(self, cfg, client, cache, clock=time.time, mono=time.monotonic, workers: int = 6):
        kw = dict(cache=cache, clock=clock, mono=mono)
        self.cache = cache
        self.clock = clock
        self.quote_ttl = cfg.quote_ttl
        self.yahoo = Yahoo(client, **kw)
        self.kraken = Kraken(client, **kw)
        self.coingecko = CoinGecko(client, api_key=cfg.coingecko_key, **kw)
        self.binance = Binance(client, **kw)
        self.ecb = Ecb(client, **kw)
        self.twelvedata = TwelveData(client, api_key=cfg.twelvedata_key, **kw)
        self.finnhub = Finnhub(client, api_key=cfg.finnhub_key, **kw)
        self.alphavantage = AlphaVantage(client, api_key=cfg.alphavantage_key, **kw)
        self.ntfy = (Ntfy(client, url=cfg.ntfy_url, token=cfg.ntfy_token, **kw)
                     if cfg.ntfy_url else None)
        self.pool = ThreadPoolExecutor(max_workers=workers, thread_name_prefix="md")

    # -- cadenas -----------------------------------------------------------------
    def market_providers(self) -> List[Provider]:
        all_ = [self.yahoo, self.kraken, self.coingecko, self.binance, self.ecb,
                self.twelvedata, self.finnhub, self.alphavantage]
        return [p for p in all_ if p.enabled]

    def quote_chain(self, sym) -> List[Provider]:
        if sym.kind == "crypto":
            chain = [self.kraken, self.coingecko, self.binance, self.yahoo]
        elif sym.kind == "fx":
            chain = [self.ecb, self.yahoo]
        else:
            chain = [self.yahoo, self.twelvedata, self.finnhub, self.alphavantage]
        return [p for p in chain if p.enabled]

    def history_chain(self, sym) -> List[Provider]:
        chain = [self.kraken, self.yahoo] if sym.kind == "crypto" else [self.yahoo, self.twelvedata]
        return [p for p in chain if p.enabled]

    @staticmethod
    def _resolve(syms: List, chain: List[Provider]) -> Tuple[Dict[str, dict], Dict[str, list]]:
        """Recorre la cadena; cada proveedor recibe solo los símbolos aún pendientes."""
        found: Dict[str, dict] = {}
        errors: Dict[str, list] = {s.canonical: [] for s in syms}
        pending = list(syms)
        for provider in chain:
            if not pending:
                break
            try:
                got, bad = provider.quotes(pending)
            except ProviderError as e:
                got, bad = {}, {s.canonical: e for s in pending}
            except Exception as e:  # noqa: BLE001 - un fallo de parseo no tumba la petición
                log.exception("%s: error inesperado", provider.name)
                got, bad = {}, {s.canonical: ProviderError(str(e)) for s in pending}
            found.update(got)
            for canonical, err in bad.items():
                if canonical in errors:
                    errors[canonical].append(err)
            pending = [s for s in pending if s.canonical not in found]
        return found, errors

    # -- /quote ------------------------------------------------------------------
    def parse_symbols(self, raw: Optional[str]) -> List:
        items = [s.strip() for s in (raw or "").split(",") if s.strip()]
        if not items:
            raise ServiceError("falta el parámetro symbols", 400)
        unique = list(dict.fromkeys(items))
        if len(unique) > MAX_SYMBOLS:
            raise ServiceError(f"como máximo {MAX_SYMBOLS} símbolos", 400)
        try:
            return [symbols.parse(s) for s in unique]
        except symbols.SymbolError as e:
            raise ServiceError(str(e), 400) from None

    def quotes(self, raw: Optional[str]) -> Dict[str, Any]:
        syms = self.parse_symbols(raw)
        found: Dict[str, dict] = {}
        errors: Dict[str, list] = {}
        pending = []
        for sym in syms:
            cached = self.cache.get_fresh(f"quote:{sym.canonical}", self.quote_ttl)
            if cached is not None:
                found[sym.canonical] = cached
            else:
                pending.append(sym)

        jobs = []
        crypto = [s for s in pending if s.kind == "crypto"]
        if crypto:  # en lote: Kraken, CoinGecko y Binance aceptan varios pares
            jobs.append(self.pool.submit(self._resolve, crypto, self.quote_chain(crypto[0])))
        for sym in pending:
            if sym.kind != "crypto":
                jobs.append(self.pool.submit(self._resolve, [sym], self.quote_chain(sym)))
        for job in jobs:
            got, bad = job.result()
            for canonical, q in got.items():
                q = finalize(canonical, q)
                self.cache.set(f"quote:{canonical}", q)
                found[canonical] = q
            errors.update(bad)

        out_quotes, out_errors = [], []
        for sym in syms:
            c = sym.canonical
            if c in found:
                out_quotes.append(found[c])
                continue
            stale = self.cache.get(f"quote:{c}")
            if stale is not None:
                out_quotes.append(dict(stale[0], stale=True))
            else:
                out_errors.append({"symbol": c, "error": failure_message(errors.get(c, []))})
        return {"quotes": out_quotes, "errors": out_errors}

    # -- /history ----------------------------------------------------------------
    def history(self, symbol: Optional[str], rng: Optional[str], interval: Optional[str]) -> Dict[str, Any]:
        if not symbol:
            raise ServiceError("falta el parámetro symbol", 400)
        try:
            sym = symbols.parse(symbol)
        except symbols.SymbolError as e:
            raise ServiceError(str(e), 400) from None
        rng = rng or "1mo"
        interval = interval or "1d"
        if rng not in RANGES:
            raise ServiceError(f"range no válido (admitidos: {', '.join(RANGES)})", 400)
        if interval not in INTERVALS:
            raise ServiceError(f"interval no válido (admitidos: {', '.join(INTERVALS)})", 400)
        if rng not in VALID_COMBOS[interval]:
            raise ServiceError(f"combinación no admitida: range={rng} interval={interval}", 400)

        key = f"history:{sym.canonical}:{rng}:{interval}"
        ttl = HISTORY_TTL_INTRADAY if interval == "1h" else HISTORY_TTL_DAILY
        cached = self.cache.get_fresh(key, ttl)
        if cached is not None:
            return cached
        errors: List[Exception] = []
        for provider in self.history_chain(sym):
            try:
                h = provider.history(sym, rng, interval)
            except ProviderError as e:
                errors.append(e)
                continue
            except Exception as e:  # noqa: BLE001
                log.exception("%s: error inesperado en histórico", provider.name)
                errors.append(ProviderError(str(e)))
                continue
            result = {"symbol": sym.canonical, "currency": h["currency"],
                      "source": provider.name, "candles": h["candles"]}
            self.cache.set(key, result)
            return result
        stale = self.cache.get(key)
        if stale is not None:
            return stale[0]
        message = failure_message(errors)
        raise ServiceError(message, 404 if message == "no encontrado" else 502)

    # -- /search -----------------------------------------------------------------
    def search(self, q: Optional[str]) -> Dict[str, Any]:
        q = (q or "").strip()
        if not q:
            raise ServiceError("falta el parámetro q", 400)
        if len(q) > 64 or _has_control(q):
            raise ServiceError("q no válido (máximo 64 caracteres)", 400)
        key = f"search:{q.lower()}"
        cached = self.cache.get_fresh(key, SEARCH_TTL)
        if cached is not None:
            return cached
        jobs = [self.pool.submit(p.search, q) for p in (self.yahoo, self.coingecko)]
        merged, seen, failures = [], set(), 0
        for job in jobs:
            try:
                items = job.result()
            except Exception as e:  # noqa: BLE001
                if not isinstance(e, ProviderError):
                    log.exception("búsqueda: error inesperado")
                failures += 1
                continue
            for item in items:
                if item["symbol"] not in seen:
                    seen.add(item["symbol"])
                    merged.append(item)
        if failures == len(jobs):
            stale = self.cache.get(key)
            if stale is not None:
                return stale[0]
            raise ServiceError("búsqueda no disponible", 502)
        result = {"results": merged[:MAX_SEARCH_RESULTS]}
        if failures == 0:
            self.cache.set(key, result)
        return result

    # -- /fx ---------------------------------------------------------------------
    def fx(self) -> Dict[str, Any]:
        try:
            return self.ecb.table()
        except ProviderError:
            raise ServiceError("tipos del BCE no disponibles", 502) from None

    # -- /notify -----------------------------------------------------------------
    def notify(self, body: Any) -> Dict[str, Any]:
        if self.ntfy is None:
            raise ServiceError("ntfy no configurado", 503)
        if not isinstance(body, dict):
            raise ServiceError("el cuerpo debe ser un objeto JSON", 400)
        title, message = body.get("title"), body.get("message")
        priority = body.get("priority") or "default"
        tags = body.get("tags") or []
        if not isinstance(message, str) or not message.strip() or len(message) > 4096:
            raise ServiceError("message es obligatorio (texto, máximo 4096)", 400)
        if title is not None and (not isinstance(title, str) or len(title) > 250 or _has_control(title)):
            raise ServiceError("title no válido (texto, máximo 250)", 400)
        if priority not in PRIORITY:
            raise ServiceError("priority debe ser default o high", 400)
        if (not isinstance(tags, list) or len(tags) > 10
                or not all(isinstance(t, str) and _TAG_RE.fullmatch(t) for t in tags)):
            raise ServiceError("tags no válido (lista de hasta 10 etiquetas simples)", 400)
        try:
            self.ntfy.send(title, message, priority, tags)
        except ProviderError:
            raise ServiceError("ntfy no disponible", 502) from None
        return {"ok": True}

    # -- /health -----------------------------------------------------------------
    def health(self) -> Dict[str, Any]:
        return {"ok": True, "providers": {p.name: p.status() for p in self.market_providers()}}

    def close(self) -> None:
        self.pool.shutdown(wait=False, cancel_futures=True)
