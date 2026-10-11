"""Proveedores con clave de API (opcionales): Twelve Data, Finnhub y Alpha Vantage.

Sin la variable de entorno correspondiente quedan desactivados y la cadena los salta
en silencio. Las claves viajan en cabecera cuando el proveedor lo admite; Alpha
Vantage solo la acepta en la query, y el log nunca escribe la query.
"""

from datetime import datetime, timezone
from typing import Any, Dict, Optional
from urllib.parse import urlencode

from errors import NotFound, ProviderError, ProviderUnavailable, RateLimited
from providers.base import RANGE_SECONDS, Provider, candle, make_quote, normalize_currency, num

# Sufijo de Yahoo -> código MIC de Twelve Data.
TD_MIC = {
    "AS": "XAMS", "MC": "XMAD", "DE": "XETR", "F": "XFRA", "PA": "XPAR", "MI": "XMIL",
    "L": "XLON", "SW": "XSWX", "LS": "XLIS", "BR": "XBRU", "TO": "XTSE", "HK": "XHKG",
}
TD_INDEX = {"^GSPC": "SPX", "^DJI": "DJI", "^IXIC": "IXIC", "^NDX": "NDX", "^IBEX": "IBEX"}
TD_COMMODITY = {"GC=F": "XAU/USD", "SI=F": "XAG/USD"}
TD_INTERVAL = {"1h": "1h", "1d": "1day", "1wk": "1week", "1mo": "1month"}
TD_SECONDS = {"1h": 3600, "1d": 86400, "1wk": 7 * 86400, "1mo": 30 * 86400}


def is_us_listing(sym) -> bool:
    """Acción/ETF de EE. UU. (sin sufijo de bolsa): lo único que cubren los planes gratis."""
    return sym.kind in ("stock", "etf") and not any(c in sym.code for c in ".^=")


def td_params(sym) -> Dict[str, str]:
    code = sym.code
    if sym.kind in ("fx", "crypto"):
        return {"symbol": f"{sym.base}/{sym.quote}"}
    if sym.kind == "index" and code in TD_INDEX:
        return {"symbol": TD_INDEX[code]}
    if sym.kind == "commodity" and code in TD_COMMODITY:
        return {"symbol": TD_COMMODITY[code]}
    if sym.kind in ("stock", "etf", "fund") and "^" not in code and "=" not in code:
        if "." not in code:
            return {"symbol": code}
        root, suffix = code.rsplit(".", 1)
        if suffix in TD_MIC:
            return {"symbol": root, "mic_code": TD_MIC[suffix]}
    raise NotFound(f"twelvedata: sin equivalencia para {sym.canonical}")


def _parse_td_time(text: str) -> Optional[float]:
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d"):
        try:
            return datetime.strptime(text, fmt).replace(tzinfo=timezone.utc).timestamp()
        except (TypeError, ValueError):
            continue
    return None


class TwelveData(Provider):
    name = "twelvedata"
    per_minute = 8  # plan gratuito

    def __init__(self, *args, api_key: Optional[str] = None, **kwargs):
        super().__init__(*args, **kwargs)
        self.api_key = api_key
        self.enabled = bool(api_key)

    def _get(self, path: str, params: Dict[str, str]) -> Dict[str, Any]:
        if not self.enabled:
            raise ProviderUnavailable("twelvedata: sin clave")
        data = self._json(f"https://api.twelvedata.com/{path}?{urlencode(params)}",
                          {"Authorization": f"apikey {self.api_key}"})
        if not isinstance(data, dict):
            raise ProviderError("twelvedata: respuesta inesperada")
        if data.get("status") == "error":
            code = data.get("code")
            if code == 429:
                self.state.rate_limited()
                raise RateLimited("twelvedata: límite de peticiones")
            if code in (400, 404):
                raise NotFound("twelvedata: no encontrado")
            self.state.failure()
            raise ProviderError(f"twelvedata: error {code}")
        return data

    def parse_quote(self, data: Dict[str, Any]) -> Dict[str, Any]:
        as_of = num(data.get("last_quote_at")) or num(data.get("timestamp"))
        return make_quote("twelvedata", data.get("close"), data.get("currency"),
                          name=data.get("name"), previous_close=data.get("previous_close"),
                          as_of=as_of, clock=self.clock)

    def quote(self, sym) -> Dict[str, Any]:
        return self.parse_quote(self._get("quote", td_params(sym)))

    def history(self, sym, rng: str, interval: str) -> Dict[str, Any]:
        span = RANGE_SECONDS[rng]
        size = 5000 if span is None else min(5000, int(span / TD_SECONDS[interval]) + 2)
        params = dict(td_params(sym), interval=TD_INTERVAL[interval], outputsize=str(size),
                      timezone="UTC", order="asc")
        data = self._get("time_series", params)
        since = 0 if span is None else self.clock() - span
        return self.parse_series(data, since)

    @staticmethod
    def parse_series(data: Dict[str, Any], since: float = 0) -> Dict[str, Any]:
        meta = data.get("meta") or {}
        currency, factor = normalize_currency(meta.get("currency"))
        candles = []
        for row in data.get("values") or []:
            t = _parse_td_time(row.get("datetime"))
            if t is None or t < since:
                continue
            item = candle(t, row.get("open"), row.get("high"), row.get("low"), row.get("close"),
                          row.get("volume"), factor)
            if item:
                candles.append(item)
        candles.sort(key=lambda c: c["t"])
        return {"currency": currency, "candles": candles}


class Finnhub(Provider):
    name = "finnhub"
    per_minute = 60

    def __init__(self, *args, api_key: Optional[str] = None, **kwargs):
        super().__init__(*args, **kwargs)
        self.api_key = api_key
        self.enabled = bool(api_key)

    def quote(self, sym) -> Dict[str, Any]:
        if not self.enabled:
            raise ProviderUnavailable("finnhub: sin clave")
        if not is_us_listing(sym):
            raise NotFound("finnhub: solo cotizaciones de EE. UU.")
        data = self._json(f"https://finnhub.io/api/v1/quote?{urlencode({'symbol': sym.code})}",
                          {"X-Finnhub-Token": self.api_key}, not_found=(404, 403))
        return self.parse_quote(data, sym)

    def parse_quote(self, data: Dict[str, Any], sym) -> Dict[str, Any]:
        # Símbolo desconocido: Finnhub responde 200 con todo a 0.
        if not isinstance(data, dict) or not num(data.get("c")) or not num(data.get("t")):
            raise NotFound("finnhub: no encontrado")
        return make_quote("finnhub", data.get("c"), "USD", name=sym.code,
                          previous_close=data.get("pc"), as_of=num(data.get("t")),
                          clock=self.clock)


class AlphaVantage(Provider):
    name = "alphavantage"
    per_minute = 5  # plan gratuito (y 25 al día)

    def __init__(self, *args, api_key: Optional[str] = None, **kwargs):
        super().__init__(*args, **kwargs)
        self.api_key = api_key
        self.enabled = bool(api_key)

    def quote(self, sym) -> Dict[str, Any]:
        if not self.enabled:
            raise ProviderUnavailable("alphavantage: sin clave")
        if not is_us_listing(sym):
            raise NotFound("alphavantage: solo cotizaciones de EE. UU.")
        params = {"function": "GLOBAL_QUOTE", "symbol": sym.code, "apikey": self.api_key}
        data = self._json(f"https://www.alphavantage.co/query?{urlencode(params)}")
        return self.parse_quote(data, sym)

    def parse_quote(self, data: Dict[str, Any], sym) -> Dict[str, Any]:
        if not isinstance(data, dict):
            raise ProviderError("alphavantage: respuesta inesperada")
        if "Note" in data or "Information" in data:
            # Así avisa Alpha Vantage de que se ha agotado el cupo.
            self.state.rate_limited()
            raise RateLimited("alphavantage: límite de peticiones")
        q = data.get("Global Quote") or {}
        if not q.get("05. price"):
            raise NotFound("alphavantage: no encontrado")
        day = _parse_td_time(q.get("07. latest trading day"))
        return make_quote("alphavantage", q.get("05. price"), "USD", name=sym.code,
                          previous_close=q.get("08. previous close"), as_of=day,
                          clock=self.clock)
