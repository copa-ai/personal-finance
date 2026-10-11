"""Salida HTTP del servicio: allowlist de hosts, solo HTTPS y sin redirecciones ajenas.

`HttpClient` es el único camino hacia la red. Antes de cada petición (y de cada
redirección) comprueba la URL contra la allowlist de hosts exactos. El transporte es
inyectable: en producción `UrllibTransport`, en los tests uno falso sin red.
"""

import json
import logging
import socket
import ssl
import time
import urllib.error
import urllib.request
from dataclasses import dataclass, field
from typing import Iterable, List, Optional, Tuple
from urllib.parse import urljoin, urlsplit

from errors import HostNotAllowed, ProviderError

log = logging.getLogger("pf-market-data.net")

MAX_BODY = 4 * 1024 * 1024
MAX_REDIRECTS = 3

# Algunos proveedores (Yahoo) rechazan clientes que no parecen un navegador.
BROWSER_UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36")


@dataclass
class Response:
    status: int
    body: bytes = b""
    headers: List[Tuple[str, str]] = field(default_factory=list)

    def header(self, name: str) -> Optional[str]:
        name = name.lower()
        for k, v in self.headers:
            if k.lower() == name:
                return v
        return None

    def header_all(self, name: str) -> List[str]:
        name = name.lower()
        return [v for k, v in self.headers if k.lower() == name]

    def json(self):
        try:
            return json.loads(self.body.decode("utf-8"))
        except (UnicodeDecodeError, ValueError) as e:
            raise ProviderError(f"JSON inválido: {e}") from None

    def text(self) -> str:
        return self.body.decode("utf-8", errors="replace")


def check_url(url: str, allowed_hosts: Iterable[str]) -> None:
    """Lanza HostNotAllowed salvo https://<host exacto permitido>[:443]/..."""
    try:
        parts = urlsplit(url)
        port = parts.port
    except ValueError:
        raise HostNotAllowed("URL mal formada") from None
    host = (parts.hostname or "").lower()
    if parts.scheme != "https":
        raise HostNotAllowed(f"solo https (esquema {parts.scheme!r})")
    if parts.username is not None or parts.password is not None:
        raise HostNotAllowed("URL con credenciales")
    if port not in (None, 443):
        raise HostNotAllowed(f"puerto no permitido: {port}")
    if host not in allowed_hosts:
        raise HostNotAllowed(f"host no permitido: {host!r}")


def redact(url: str) -> str:
    """Host y ruta, sin query: las claves de API viajan en la query."""
    parts = urlsplit(url)
    return f"{parts.hostname}{parts.path}"


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    # Las redirecciones las decide HttpClient, que vuelve a pasar la allowlist.
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class UrllibTransport:
    """Transporte real con urllib: sin proxies del entorno y sin seguir redirecciones."""

    def __init__(self):
        ctx = ssl.create_default_context()
        self._opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            urllib.request.HTTPSHandler(context=ctx),
            _NoRedirect(),
        )

    def request(self, method: str, url: str, headers: dict, body: Optional[bytes],
                timeout: float) -> Response:
        req = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            with self._opener.open(req, timeout=timeout) as resp:
                data = resp.read(MAX_BODY + 1)
                status, hdrs = resp.status, list(resp.headers.items())
        except urllib.error.HTTPError as e:
            try:
                data = e.read(MAX_BODY + 1)
            except Exception:  # noqa: BLE001 - cuerpo ilegible: da igual
                data = b""
            status, hdrs = e.code, list(e.headers.items()) if e.headers else []
        except (urllib.error.URLError, socket.timeout, TimeoutError, ssl.SSLError,
                ConnectionError, OSError) as e:
            reason = getattr(e, "reason", e)
            raise ProviderError(f"error de red: {reason}") from None
        if len(data) > MAX_BODY:
            raise ProviderError("respuesta demasiado grande")
        return Response(status=status, body=data, headers=hdrs)


class HttpClient:
    def __init__(self, transport, allowed_hosts: Iterable[str], timeout: float = 8.0):
        self.transport = transport
        self.allowed_hosts = frozenset(h.lower() for h in allowed_hosts)
        self.timeout = timeout

    def request(self, method: str, url: str, headers: Optional[dict] = None,
                body: Optional[bytes] = None) -> Response:
        headers = dict(headers or {})
        headers.setdefault("User-Agent", BROWSER_UA)
        headers.setdefault("Accept", "application/json, text/xml;q=0.9, */*;q=0.5")
        for _ in range(MAX_REDIRECTS + 1):
            check_url(url, self.allowed_hosts)
            started = time.monotonic()
            resp = self.transport.request(method, url, headers, body, self.timeout)
            log.info("%s %s -> %s (%.0f ms)", method, redact(url), resp.status,
                     (time.monotonic() - started) * 1000)
            location = resp.header("Location")
            if resp.status not in (301, 302, 303, 307, 308) or not location or method != "GET":
                return resp
            target = urljoin(url, location)
            # Solo se siguen redirecciones dentro del mismo host.
            if (urlsplit(target).hostname or "").lower() != (urlsplit(url).hostname or "").lower():
                return resp
            url = target
        raise ProviderError("demasiadas redirecciones")

    def get(self, url: str, headers: Optional[dict] = None) -> Response:
        return self.request("GET", url, headers)
