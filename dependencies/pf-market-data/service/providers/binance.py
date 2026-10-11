"""Binance (API pública): ticker/24hr en lote.

Para USD se usa el par BASEUSDT: USDT ≈ USD, aproximación aceptable como respaldo
(la divisa devuelta sigue siendo USD). Para EUR y otras, el par real BASEEUR.
`previous_close` es `openPrice`: el precio de hace 24 h.
"""

import json
from typing import Any, Dict, List, Tuple
from urllib.parse import urlencode

from errors import NotFound, ProviderError, RateLimited
from providers.base import Provider, make_quote, num
from providers.coins import coin_name

BASE_URL = "https://api.binance.com/api/v3/ticker/24hr"


def pair(sym) -> str:
    return f"{sym.base}{'USDT' if sym.quote == 'USD' else sym.quote}"


class Binance(Provider):
    name = "binance"
    per_minute = 300

    def _fetch(self, pairs: List[str]) -> List[Dict[str, Any]]:
        if len(pairs) == 1:
            params = {"symbol": pairs[0]}
        else:
            params = {"symbols": json.dumps(pairs, separators=(",", ":"))}
        resp = self._request(f"{BASE_URL}?{urlencode(params)}")
        if resp.status == 418:  # baneo temporal de IP: igual que 429
            self.state.rate_limited()
            raise RateLimited("binance: HTTP 418")
        if resp.status == 400:
            # -1121: símbolo inválido (un solo par desconocido invalida el lote).
            self.state.success()
            raise NotFound("binance: par desconocido")
        if not 200 <= resp.status < 300:
            self.state.failure()
            raise ProviderError(f"binance: HTTP {resp.status}")
        data = resp.json()
        self.state.success()
        return data if isinstance(data, list) else [data]

    def quotes(self, syms: List) -> Tuple[Dict[str, dict], Dict[str, Exception]]:
        found, errors = {}, {}
        by_pair = {}
        for sym in syms:
            by_pair.setdefault(pair(sym), []).append(sym)
        try:
            rows = self._fetch(sorted(by_pair))
        except NotFound:
            if len(by_pair) == 1:
                for sym in syms:
                    errors[sym.canonical] = NotFound("binance: par desconocido")
                return found, errors
            rows = []
            for p, group in by_pair.items():
                try:
                    rows += self._fetch([p])
                except ProviderError as e:
                    for sym in group:
                        errors[sym.canonical] = e
        index = {r.get("symbol"): r for r in rows if isinstance(r, dict)}
        for p, group in by_pair.items():
            for sym in group:
                if sym.canonical in errors:
                    continue
                row = index.get(p)
                if row is None:
                    errors[sym.canonical] = NotFound("binance: par no devuelto")
                    continue
                try:
                    found[sym.canonical] = self.parse_row(row, sym)
                except ProviderError as e:
                    errors[sym.canonical] = e
        return found, errors

    def parse_row(self, row: Dict[str, Any], sym) -> Dict[str, Any]:
        close_ms = num(row.get("closeTime"))
        return make_quote("binance", row.get("lastPrice"), sym.quote, name=coin_name(sym.base),
                          previous_close=num(row.get("openPrice")),
                          as_of=close_ms / 1000 if close_ms else None, clock=self.clock)

    def quote(self, sym) -> Dict[str, Any]:
        found, errors = self.quotes([sym])
        if sym.canonical in found:
            return found[sym.canonical]
        raise errors[sym.canonical]
