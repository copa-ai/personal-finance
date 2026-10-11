"""Yahoo Finance (sin clave): chart para cotización e histórico, search para búsqueda.

Es frágil: exige User-Agent de navegador, a veces pide cookie+crumb (401/403) y
limita con 429. Se alterna entre query1 y query2; un 429 pone el proveedor en
cooldown y la cadena pasa al siguiente.
"""

import itertools
import threading
from typing import Any, Dict, List, Optional
from urllib.parse import quote as urlquote, urlencode

import symbols
from errors import NotFound, ProviderError
from providers.base import Provider, candle, make_quote, normalize_currency, num

HOSTS = ("query1.finance.yahoo.com", "query2.finance.yahoo.com")
COOKIE_URL = "https://fc.yahoo.com/"
CRUMB_URL = "https://query2.finance.yahoo.com/v1/test/getcrumb"

YAHOO_INTERVAL = {"1h": "60m", "1d": "1d", "1wk": "1wk", "1mo": "1mo"}

# quoteType de Yahoo -> kind canónico.
QUOTE_TYPES = {
    "EQUITY": "stock", "ETF": "etf", "INDEX": "index", "CURRENCY": "fx",
    "FUTURE": "commodity", "MUTUALFUND": "fund", "CRYPTOCURRENCY": "crypto",
}


def canonical_from_yahoo(ticker: str, quote_type: str) -> Optional[str]:
    kind = QUOTE_TYPES.get(quote_type or "")
    if not kind or not isinstance(ticker, str):
        return None
    code = ticker.upper()
    if kind == "fx":
        code = code[:-2] if code.endswith("=X") else code
        if len(code) == 3:  # "EUR=X" es USD/EUR en Yahoo
            code = "USD" + code
    candidate = f"{kind}:{code}"
    return candidate if symbols.is_valid(candidate) else None


class Yahoo(Provider):
    name = "yahoo"
    per_minute = 30

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._turn = itertools.count()
        self._auth_lock = threading.Lock()
        self._cookie: Optional[str] = None
        self._crumb: Optional[str] = None

    def _host(self) -> str:
        return HOSTS[next(self._turn) % len(HOSTS)]

    def _refresh_crumb(self) -> bool:
        """Cookie de fc.yahoo.com + crumb de getcrumb. Un único intento."""
        with self._auth_lock:
            resp = self._request(COOKIE_URL)
            cookies = [c.split(";", 1)[0].strip() for c in resp.header_all("Set-Cookie")]
            cookie = "; ".join(c for c in cookies if "=" in c)
            if not cookie:
                return False
            resp = self._request(CRUMB_URL, {"Cookie": cookie, "Accept": "text/plain"})
            crumb = resp.text().strip()
            if resp.status != 200 or not crumb or len(crumb) > 64 or "<" in crumb or " " in crumb:
                return False
            self._cookie, self._crumb = cookie, crumb
            return True

    def _get(self, path: str, params: Dict[str, str]) -> Any:
        """GET JSON con reintento único vía cookie+crumb ante 401/403."""
        for attempt in (0, 1):
            query = dict(params)
            headers = {}
            if self._crumb:
                query["crumb"] = self._crumb
                headers["Cookie"] = self._cookie
            url = f"https://{self._host()}{path}?{urlencode(query)}"
            resp = self._request(url, headers)
            if resp.status in (401, 403) and attempt == 0 and self._refresh_crumb():
                continue
            if resp.status == 404:
                self.state.success()
                raise NotFound("yahoo: no encontrado")
            if not 200 <= resp.status < 300:
                self.state.failure()
                raise ProviderError(f"yahoo: HTTP {resp.status}")
            data = resp.json()
            self.state.success()
            return data
        raise ProviderError("yahoo: autenticación rechazada")

    def _chart(self, ticker: str, rng: str, interval: str) -> Dict[str, Any]:
        data = self._get(f"/v8/finance/chart/{urlquote(ticker, safe='')}",
                         {"range": rng, "interval": interval, "includePrePost": "false"})
        chart = (data or {}).get("chart") or {}
        results = chart.get("result") or []
        if not results:
            raise NotFound("yahoo: sin datos")
        return results[0]

    @staticmethod
    def parse_quote(result: Dict[str, Any], clock) -> Dict[str, Any]:
        meta = result.get("meta") or {}
        prev = num(meta.get("chartPreviousClose"))
        if prev is None:
            prev = num(meta.get("previousClose"))
        return make_quote(
            "yahoo", meta.get("regularMarketPrice"), meta.get("currency"),
            name=meta.get("longName") or meta.get("shortName"),
            previous_close=prev, as_of=num(meta.get("regularMarketTime")), clock=clock,
        )

    @staticmethod
    def parse_history(result: Dict[str, Any]) -> Dict[str, Any]:
        meta = result.get("meta") or {}
        currency, factor = normalize_currency(meta.get("currency"))
        stamps = result.get("timestamp") or []
        quotes = ((result.get("indicators") or {}).get("quote") or [{}])[0] or {}

        def col(name):
            values = quotes.get(name) or []
            return lambda i: values[i] if i < len(values) else None

        o, h, l, c, v = (col(n) for n in ("open", "high", "low", "close", "volume"))
        candles = []
        for i, t in enumerate(stamps):
            item = candle(t, o(i), h(i), l(i), c(i), v(i), factor) if num(t) is not None else None
            if item:
                candles.append(item)
        return {"currency": currency, "candles": candles}

    def quote(self, sym) -> Dict[str, Any]:
        # range=1d: chartPreviousClose es entonces el cierre de la sesión anterior.
        result = self._chart(sym.yahoo_ticker, "1d", "1d")
        return self.parse_quote(result, self.clock)

    def history(self, sym, rng: str, interval: str) -> Dict[str, Any]:
        result = self._chart(sym.yahoo_ticker, rng, YAHOO_INTERVAL[interval])
        return self.parse_history(result)

    @staticmethod
    def parse_search(data: Dict[str, Any]) -> List[Dict[str, Any]]:
        out = []
        for item in (data or {}).get("quotes") or []:
            if not isinstance(item, dict):
                continue
            canonical = canonical_from_yahoo(item.get("symbol"), item.get("quoteType"))
            if not canonical:
                continue
            out.append({
                "symbol": canonical,
                "name": item.get("longname") or item.get("shortname") or item.get("symbol"),
                "type": canonical.split(":", 1)[0],
                "exchange": item.get("exchDisp") or item.get("exchange") or None,
            })
        return out

    def search(self, q: str) -> List[Dict[str, Any]]:
        data = self._get("/v1/finance/search", {
            "q": q, "quotesCount": "15", "newsCount": "0", "listsCount": "0",
        })
        return self.parse_search(data)
