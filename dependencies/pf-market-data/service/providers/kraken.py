"""Kraken (API pública, sin clave): Ticker para cotizaciones en lote y OHLC para histórico.

Pares reales en fiat (USD, EUR...), no stablecoins. `previous_close` es el campo
`o` del Ticker: la apertura del día en curso (00:00 UTC), que hace de cierre previo.
"""

from typing import Any, Dict, List, Tuple
from urllib.parse import urlencode

from errors import NotFound, ProviderError, ProviderUnavailable, RateLimited
from providers.base import RANGE_SECONDS, INTERVAL_SECONDS, Provider, candle, make_quote, num
from providers.coins import coin_name

BASE_URL = "https://api.kraken.com/0/public"
# Kraken usa sus propios códigos para algunos activos.
ASSET = {"BTC": "XBT", "DOGE": "XDG"}
QUOTES = {"USD", "EUR", "GBP", "CAD", "JPY", "CHF", "AUD", "USDT", "USDC"}
OHLC_INTERVAL = {"1h": 60, "1d": 1440, "1wk": 10080}
OHLC_MAX_POINTS = 720


def pair(sym) -> str:
    return f"{ASSET.get(sym.base, sym.base)}{sym.quote}"


def result_keys(sym) -> List[str]:
    """Nombres con los que Kraken puede devolver el par (XBTUSD -> XXBTZUSD...)."""
    b, q = ASSET.get(sym.base, sym.base), sym.quote
    return [f"{b}{q}", f"X{b}Z{q}", f"X{b}X{q}", f"X{b}{q}", f"{b}Z{q}"]


class Kraken(Provider):
    name = "kraken"
    per_minute = 60

    def _call(self, path: str, params: Dict[str, Any]) -> Dict[str, Any]:
        data = self._json(f"{BASE_URL}/{path}?{urlencode(params)}")
        errors = [str(e) for e in (data or {}).get("error") or []]
        if errors:
            text = "; ".join(errors)
            if "Rate limit" in text or "Too many requests" in text:
                self.state.rate_limited()
                raise RateLimited(f"kraken: {text}")
            if "Unknown asset pair" in text:
                raise NotFound("kraken: par desconocido")
            self.state.failure()
            raise ProviderError(f"kraken: {text}")
        return (data or {}).get("result") or {}

    @staticmethod
    def find(result: Dict[str, Any], sym) -> Any:
        for key in result_keys(sym):
            if key in result:
                return result[key]
        return None

    def parse_ticker(self, entry: Dict[str, Any], sym) -> Dict[str, Any]:
        last = (entry.get("c") or [None])[0]
        return make_quote("kraken", last, sym.quote, name=coin_name(sym.base),
                          previous_close=num(entry.get("o")), clock=self.clock)

    def quotes(self, syms: List) -> Tuple[Dict[str, dict], Dict[str, Exception]]:
        found, errors = {}, {}
        usable = []
        for sym in syms:
            if sym.quote in QUOTES:
                usable.append(sym)
            else:
                errors[sym.canonical] = NotFound(f"kraken: sin par en {sym.quote}")
        if not usable:
            return found, errors
        try:
            result = self._call("Ticker", {"pair": ",".join(sorted({pair(s) for s in usable}))})
            groups = [(usable, result)]
        except NotFound:
            # Un par desconocido tumba el lote entero: se repite uno a uno.
            if len(usable) == 1:
                errors[usable[0].canonical] = NotFound("kraken: par desconocido")
                return found, errors
            groups = []
            for sym in usable:
                try:
                    groups.append(([sym], self._call("Ticker", {"pair": pair(sym)})))
                except ProviderError as e:
                    errors[sym.canonical] = e
        for group, result in groups:
            for sym in group:
                entry = self.find(result, sym)
                if entry is None:
                    errors[sym.canonical] = NotFound("kraken: par no devuelto")
                    continue
                try:
                    found[sym.canonical] = self.parse_ticker(entry, sym)
                except ProviderError as e:
                    errors[sym.canonical] = e
        return found, errors

    def quote(self, sym) -> Dict[str, Any]:
        found, errors = self.quotes([sym])
        if sym.canonical in found:
            return found[sym.canonical]
        raise errors[sym.canonical]

    def history(self, sym, rng: str, interval: str) -> Dict[str, Any]:
        minutes = OHLC_INTERVAL.get(interval)
        span = RANGE_SECONDS[rng]
        # Kraken devuelve como mucho 720 velas: si el rango no cabe, que lo haga Yahoo.
        if minutes is None or span is None or span / INTERVAL_SECONDS[interval] > OHLC_MAX_POINTS:
            raise ProviderUnavailable("kraken: combinación de rango/intervalo no soportada")
        if sym.quote not in QUOTES:
            raise NotFound(f"kraken: sin par en {sym.quote}")
        since = int(self.clock() - span)
        result = self._call("OHLC", {"pair": pair(sym), "interval": minutes, "since": since})
        rows = self.find(result, sym)
        if rows is None:
            raise NotFound("kraken: par no devuelto")
        return {"currency": sym.quote, "candles": self.parse_ohlc(rows, since)}

    @staticmethod
    def parse_ohlc(rows: List[list], since: float = 0) -> List[dict]:
        # [time, open, high, low, close, vwap, volume, count]
        out = []
        for row in rows:
            if not isinstance(row, list) or len(row) < 7 or (num(row[0]) or 0) < since:
                continue
            item = candle(num(row[0]), row[1], row[2], row[3], row[4], row[6])
            if item:
                out.append(item)
        return out
