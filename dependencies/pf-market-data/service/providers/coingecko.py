"""CoinGecko (sin clave o con clave demo): simple/price en lote y búsqueda de monedas.

`previous_close` se deriva del cambio en 24 h (ventana móvil, no cierre diario):
precio / (1 + cambio% / 100).
"""

from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urlencode

import symbols
from errors import NotFound, ProviderError
from providers.base import Provider, make_quote, num
from providers.coins import COINS

BASE_URL = "https://api.coingecko.com/api/v3"
ID_TTL = 7 * 86400
ID_NEGATIVE_TTL = 86400


class CoinGecko(Provider):
    name = "coingecko"
    per_minute = 25

    def __init__(self, *args, api_key: Optional[str] = None, **kwargs):
        super().__init__(*args, **kwargs)
        self.api_key = api_key

    def _headers(self) -> Dict[str, str]:
        return {"x-cg-demo-api-key": self.api_key} if self.api_key else {}

    def _get(self, path: str, params: Dict[str, str]) -> Any:
        return self._json(f"{BASE_URL}/{path}?{urlencode(params)}", self._headers())

    def search_raw(self, q: str) -> List[Dict[str, Any]]:
        data = self._get("search", {"query": q})
        return [c for c in (data or {}).get("coins") or [] if isinstance(c, dict)]

    @staticmethod
    def best_id(coins: List[Dict[str, Any]], base: str) -> Optional[Tuple[str, str]]:
        """La moneda con ese símbolo exacto y mejor (menor) market_cap_rank."""
        matches = [c for c in coins if str(c.get("symbol", "")).upper() == base and c.get("id")]
        if not matches:
            return None
        best = min(matches, key=lambda c: c.get("market_cap_rank") or 10 ** 9)
        return best["id"], best.get("name") or base

    def coin_id(self, base: str) -> Tuple[str, str]:
        if base in COINS:
            return COINS[base]
        key = f"cgid:{base}"
        hit = self.cache.get(key) if self.cache else None
        if hit is not None:
            value, age = hit
            if value.get("id") and age < ID_TTL:
                return value["id"], value["name"]
            if not value.get("id") and age < ID_NEGATIVE_TTL:
                raise NotFound(f"coingecko: {base} desconocido")
        best = self.best_id(self.search_raw(base), base)
        if self.cache:
            self.cache.set(key, {"id": best[0], "name": best[1]} if best else {"id": None})
        if not best:
            raise NotFound(f"coingecko: {base} desconocido")
        return best

    def quotes(self, syms: List) -> Tuple[Dict[str, dict], Dict[str, Exception]]:
        found, errors, wanted = {}, {}, {}
        for sym in syms:
            try:
                wanted[sym.canonical] = (sym, *self.coin_id(sym.base))
            except ProviderError as e:
                errors[sym.canonical] = e
        if not wanted:
            return found, errors
        ids = sorted({w[1] for w in wanted.values()})
        vs = sorted({w[0].quote.lower() for w in wanted.values()})
        data = self._get("simple/price", {
            "ids": ",".join(ids), "vs_currencies": ",".join(vs),
            "include_24hr_change": "true", "include_last_updated_at": "true",
        })
        for canonical, (sym, coin, name) in wanted.items():
            try:
                found[canonical] = self.parse_price(data, coin, name, sym)
            except ProviderError as e:
                errors[canonical] = e
        return found, errors

    def parse_price(self, data: Dict[str, Any], coin: str, name: str, sym) -> Dict[str, Any]:
        entry = (data or {}).get(coin) or {}
        vs = sym.quote.lower()
        price = num(entry.get(vs))
        if price is None:
            raise NotFound(f"coingecko: sin precio en {sym.quote}")
        change = num(entry.get(f"{vs}_24h_change"))
        prev = None
        if change is not None and change > -100:
            prev = float(f"{price / (1 + change / 100):.10g}")
        return make_quote("coingecko", price, sym.quote, name=name, previous_close=prev,
                          as_of=num(entry.get("last_updated_at")), clock=self.clock)

    def quote(self, sym) -> Dict[str, Any]:
        found, errors = self.quotes([sym])
        if sym.canonical in found:
            return found[sym.canonical]
        raise errors[sym.canonical]

    @staticmethod
    def parse_search(coins: List[Dict[str, Any]], limit: int = 10) -> List[Dict[str, Any]]:
        out = []
        for coin in coins:
            canonical = f"crypto:{str(coin.get('symbol', '')).upper()}-USD"
            if symbols.is_valid(canonical):
                out.append({"symbol": canonical, "name": coin.get("name") or canonical,
                            "type": "crypto", "exchange": None})
            if len(out) >= limit:
                break
        return out

    def search(self, q: str) -> List[Dict[str, Any]]:
        return self.parse_search(self.search_raw(q))
