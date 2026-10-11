"""Servidor HTTP de pf-market-data (http.server, solo biblioteca estándar)."""

import json
import logging
import os
import signal
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

# Arrancado como `python3 /ruta/server.py` el directorio ya está en sys.path; se
# asegura también para `-I`, que no lo añade.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import config  # noqa: E402
from cache import Cache  # noqa: E402
from market import Market, ServiceError  # noqa: E402
from netclient import HttpClient, UrllibTransport  # noqa: E402

log = logging.getLogger("pf-market-data")

MAX_BODY = 16 * 1024


class Server(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, address, handler, market: Market):
        self.market = market
        super().__init__(address, handler)


class Handler(BaseHTTPRequestHandler):
    server_version = "pf-market-data"
    sys_version = ""
    protocol_version = "HTTP/1.1"
    timeout = 30  # cliente inactivo: se cierra la conexión

    def log_message(self, fmt, *args):
        log.info("%s %s", self.address_string(), fmt % args)

    def _send(self, status: int, payload, close: bool = False) -> None:
        body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        if close:
            self.send_header("Connection", "close")
            self.close_connection = True
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def _error(self, status: int, message: str, close: bool = False) -> None:
        self._send(status, {"error": message}, close)

    def _query(self):
        params = parse_qs(urlsplit(self.path).query, keep_blank_values=True)
        return lambda name: (params.get(name) or [None])[0]

    def _dispatch(self, fn) -> None:
        try:
            self._send(200, fn())
        except ServiceError as e:
            self._error(e.status, e.message)
        except Exception:  # noqa: BLE001
            log.exception("error interno en %s", urlsplit(self.path).path)
            self._error(500, "error interno")

    def do_GET(self):
        path = urlsplit(self.path).path
        market: Market = self.server.market
        q = self._query()
        routes = {
            "/health": market.health,
            "/quote": lambda: market.quotes(q("symbols")),
            "/history": lambda: market.history(q("symbol"), q("range"), q("interval")),
            "/search": lambda: market.search(q("q")),
            "/fx": market.fx,
        }
        if path == "/notify":
            return self._error(405, "método no permitido")
        if path not in routes:
            return self._error(404, "ruta no encontrada")
        self._dispatch(routes[path])

    def do_POST(self):
        path = urlsplit(self.path).path
        if path != "/notify":
            known = {"/health", "/quote", "/history", "/search", "/fx"}
            status, msg = (405, "método no permitido") if path in known else (404, "ruta no encontrada")
            # Cuerpo sin leer: se cierra para que no se interprete como otra petición.
            return self._error(status, msg, close=True)
        try:
            length = int(self.headers.get("Content-Length") or "-1")
        except ValueError:
            length = -1
        if length < 0:
            return self._error(411, "falta Content-Length", close=True)
        if length > MAX_BODY:
            return self._error(413, "cuerpo demasiado grande", close=True)
        raw = self.rfile.read(length)
        try:
            body = json.loads(raw.decode("utf-8")) if raw else None
        except (UnicodeDecodeError, ValueError):
            return self._error(400, "JSON no válido")
        self._dispatch(lambda: self.server.market.notify(body))

    def _not_allowed(self):
        self._error(405, "método no permitido", close=True)

    do_PUT = do_DELETE = do_PATCH = _not_allowed


def build(cfg: config.Config, transport=None) -> Market:
    cache = Cache(cfg.cache_path)
    client = HttpClient(transport or UrllibTransport(), cfg.allowed_hosts, cfg.http_timeout)
    return Market(cfg, client, cache)


def main() -> int:
    logging.basicConfig(level=logging.INFO, stream=sys.stderr,
                        format="[pf-market-data] %(asctime)s %(levelname)s %(name)s: %(message)s")
    try:
        cfg = config.load()
    except config.ConfigError as e:
        log.error("FATAL: %s", e)
        return 2
    market = build(cfg)
    httpd = Server(("0.0.0.0", cfg.port), Handler, market)
    extra = ", ".join(sorted(cfg.extra_hosts)) or "ninguno"
    log.info("escuchando en 0.0.0.0:%d; proveedores: %s; hosts extra: %s", cfg.port,
             ", ".join(p.name for p in market.market_providers()), extra)

    def stop(signum, _frame):
        log.info("señal %d, parando", signum)
        threading.Thread(target=httpd.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    try:
        httpd.serve_forever()
    finally:
        httpd.server_close()
        market.close()
        market.cache.close()
    log.info("parado")
    return 0


if __name__ == "__main__":
    sys.exit(main())
