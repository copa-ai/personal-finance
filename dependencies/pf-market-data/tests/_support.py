"""Utilidades de test: rutas de import, transporte HTTP falso, reloj falso y fixtures.

Ningún test abre una conexión a Internet: todo pasa por `FakeTransport`, que
responde con las fixtures de `tests/fixtures/` (formas reales de cada API).
"""

import json
import os
import sys
from urllib.parse import parse_qs, urlsplit

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SERVICE = os.path.join(ROOT, "service")
FIXTURES = os.path.join(HERE, "fixtures")
if SERVICE not in sys.path:
    sys.path.insert(0, SERVICE)

import config  # noqa: E402
from cache import Cache  # noqa: E402
from errors import ProviderError  # noqa: E402
from market import Market  # noqa: E402
from netclient import HttpClient, Response  # noqa: E402


def fixture_bytes(name: str) -> bytes:
    with open(os.path.join(FIXTURES, name), "rb") as handle:
        return handle.read()


def fixture(name: str, status: int = 200, headers=None) -> Response:
    return Response(status=status, body=fixture_bytes(name), headers=list(headers or []))


def json_response(obj, status: int = 200, headers=None) -> Response:
    return Response(status=status, body=json.dumps(obj).encode(), headers=list(headers or []))


def status(code: int, body: bytes = b"", headers=None) -> Response:
    return Response(status=code, body=body, headers=list(headers or []))


class FakeClock:
    def __init__(self, start: float = 1791720000.0):  # 2026-10-11T10:40:00Z
        self.now = start

    def __call__(self) -> float:
        return self.now

    def advance(self, seconds: float) -> None:
        self.now += seconds


class FakeTransport:
    """Responde según (host, ruta[, parámetros]); registra cada llamada.

    `response` puede ser un Response, una lista (se consume en orden; el último se
    repite) o una función (method, url, headers, body) -> Response. Una petición
    sin ruta es un error de red.
    """

    def __init__(self):
        self.routes = []
        self.calls = []

    def add(self, host, path, response, **params):
        self.routes.append((host, path, params, response))
        return self

    def request(self, method, url, headers, body, timeout):
        parts = urlsplit(url)
        query = {k: v[0] for k, v in parse_qs(parts.query).items()}
        self.calls.append({"method": method, "url": url, "host": parts.hostname,
                           "path": parts.path, "query": query, "headers": dict(headers),
                           "body": body})
        for host, path, params, response in self.routes:
            if host != parts.hostname or path != parts.path:
                continue
            if any(query.get(k) != v for k, v in params.items()):
                continue
            if isinstance(response, list):
                return response.pop(0) if len(response) > 1 else response[0]
            if callable(response):
                return response(method, url, headers, body)
            return response
        raise ProviderError(f"error de red simulado: sin ruta para {parts.hostname}{parts.path}")

    def hosts(self):
        return [c["host"] for c in self.calls]

    def calls_to(self, host):
        return [c for c in self.calls if c["host"] == host]


def make_market(transport=None, clock=None, env=None, cache=None):
    transport = transport or FakeTransport()
    clock = clock or FakeClock()
    values = {"MD_CACHE_PATH": ":memory:"}
    values.update(env or {})
    cfg = config.load(values)
    cache = cache or Cache(":memory:", clock=clock)
    client = HttpClient(transport, cfg.allowed_hosts, cfg.http_timeout)
    market = Market(cfg, client, cache, clock=clock, mono=clock, workers=4)
    return market, transport, clock


# Rutas de uso habitual.
YQ1, YQ2 = "query1.finance.yahoo.com", "query2.finance.yahoo.com"
KRAKEN, CG, BINANCE, ECB = "api.kraken.com", "api.coingecko.com", "api.binance.com", "www.ecb.europa.eu"


def add_yahoo(transport, ticker, response):
    path = "/v8/finance/chart/" + ticker.replace("^", "%5E").replace("=", "%3D")
    transport.add(YQ1, path, response)
    transport.add(YQ2, path, response)


def add_ecb(transport):
    transport.add(ECB, "/stats/eurofxref/eurofxref-daily.xml", fixture("ecb_daily.xml"))
    transport.add(ECB, "/stats/eurofxref/eurofxref-hist-90d.xml", fixture("ecb_hist_90d.xml"))
