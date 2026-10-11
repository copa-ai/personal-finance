"""BCE: tipos de referencia diarios (base EUR), cacheados 6 h.

Las cotizaciones fx se calculan cruzando la tabla: cualquier par entre divisas del
BCE, en ambos sentidos. `previous_close` sale del día hábil anterior de la serie de
90 días del propio BCE, para que precio y cierre previo sean comparables.
"""

import threading
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional, Tuple

from errors import NotFound, ProviderError
from providers.base import Provider, make_quote

DAILY_URL = "https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml"
HIST_URL = "https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml"
TTL = 6 * 3600
# El BCE publica hacia las 16:00 CET; as_of se fija a las 15:00 UTC del día.
PUBLISH_TIME = "T15:00:00Z"

Day = Tuple[str, Dict[str, float]]


def parse_xml(body: bytes) -> List[Day]:
    """Lista de (fecha, {divisa: tipo}) en el orden del fichero (más reciente primero)."""
    try:
        root = ET.fromstring(body)
    except ET.ParseError as e:
        raise ProviderError(f"ecb: XML inválido: {e}") from None
    days: List[Day] = []
    for el in root.iter():
        if not el.tag.endswith("Cube"):
            continue
        if "time" in el.attrib:
            days.append((el.attrib["time"], {}))
        elif "currency" in el.attrib and days:
            try:
                rate = float(el.attrib.get("rate", ""))
            except ValueError:
                continue
            if rate > 0:
                days[-1][1][el.attrib["currency"].upper()] = rate
    days = [d for d in days if d[1]]
    if not days:
        raise ProviderError("ecb: tabla vacía")
    return days


def cross(rates: Dict[str, float], base: str, quote: str) -> Optional[float]:
    table = dict(rates, EUR=1.0)
    if base not in table or quote not in table:
        return None
    return float(f"{table[quote] / table[base]:.8g}")


class Ecb(Provider):
    name = "ecb"
    per_minute = 10

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        # Una sola descarga a la vez: varias cotizaciones fx en paralelo comparten tabla.
        self._fetch_lock = threading.Lock()

    def _table(self, key: str, url: str, allow_stale: bool) -> List[Day]:
        with self._fetch_lock:
            return self._table_locked(key, url, allow_stale)

    def _table_locked(self, key: str, url: str, allow_stale: bool) -> List[Day]:
        if self.cache:
            fresh = self.cache.get_fresh(key, TTL)
            if fresh is not None:
                return [(d, r) for d, r in fresh]
        try:
            resp = self._request(url, {"Accept": "application/xml, text/xml"})
            if not 200 <= resp.status < 300:
                self.state.failure()
                raise ProviderError(f"ecb: HTTP {resp.status}")
            days = parse_xml(resp.body)
        except ProviderError:
            # Para /fx y para el cierre previo, mejor tipos viejos que nada. Para el
            # precio de una cotización no: ahí decide Market y la marca `stale`.
            stale = self.cache.get(key) if self.cache and allow_stale else None
            if stale is not None:
                return [(d, r) for d, r in stale[0]]
            if self.state.status() != "cooldown":
                self.state.failure()
            raise
        self.state.success()
        if self.cache:
            self.cache.set(key, days)
        return days

    def daily(self, allow_stale: bool = False) -> Day:
        return self._table("ecb:daily", DAILY_URL, allow_stale)[0]

    def history_days(self) -> List[Day]:
        return self._table("ecb:hist90", HIST_URL, True)

    def table(self) -> Dict[str, Any]:
        date, rates = self.daily(allow_stale=True)
        return {"date": date, "base": "EUR", "rates": dict(sorted(rates.items())), "source": "ecb"}

    def quote(self, sym) -> Dict[str, Any]:
        if sym.kind != "fx":
            return super().quote(sym)
        base, quote = sym.base, sym.quote
        date, rates = self.daily()
        price = cross(rates, base, quote)
        if price is None:
            raise NotFound(f"ecb: {base}/{quote} no está en la tabla del BCE")
        prev = None
        try:
            for day, day_rates in self.history_days():
                if day < date:
                    prev = cross(day_rates, base, quote)
                    break
        except ProviderError:
            prev = None
        as_of = datetime.strptime(date + PUBLISH_TIME, "%Y-%m-%dT%H:%M:%SZ")
        return make_quote("ecb", price, quote, name=f"{base}/{quote}", previous_close=prev,
                          as_of=as_of.replace(tzinfo=timezone.utc).timestamp(), clock=self.clock)
