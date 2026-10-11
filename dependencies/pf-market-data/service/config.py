"""Contrato de entorno de pf-market-data: qué variables se leen y qué valen por defecto.

Todo se lee una vez al arrancar. Un valor inválido detiene el servicio en vez de
sustituirse en silencio por el defecto (mismo criterio que yt-transcript).
La lista de variables debe coincidir con `envs` en `.service/service.json`.
"""

import ipaddress
import os
import re
import tempfile
from dataclasses import dataclass, field
from typing import Dict, Optional, Tuple
from urllib.parse import urlsplit

# Hosts exactos a los que el servicio puede conectarse. Es la frontera de seguridad:
# se compara contra el hostname ya parseado, nunca contra el texto de la URL.
BASE_ALLOWED_HOSTS = frozenset({
    "query1.finance.yahoo.com",
    "query2.finance.yahoo.com",
    "fc.yahoo.com",
    "api.kraken.com",
    "api.coingecko.com",
    "api.binance.com",
    "www.ecb.europa.eu",
    "api.twelvedata.com",
    "finnhub.io",
    "www.alphavantage.co",
})

# Resolvers públicos que se usan bajo nodo si no se indica otra cosa: nodo no sirve
# DNS ni escribe /etc/resolv.conf en el invitado. glibc lee como máximo tres.
DEFAULT_DNS_SERVERS = ("9.9.9.9", "1.1.1.1", "8.8.8.8")
MAX_DNS_SERVERS = 3

DEFAULT_CACHE_PATH = "/data/cache.sqlite"

ENV_NAMES = (
    "MD_PORT",
    "MD_CACHE_PATH",
    "MD_QUOTE_TTL",
    "MD_HTTP_TIMEOUT",
    "MD_DNS_SERVERS",
    "MD_NTFY_URL",
    "MD_NTFY_TOKEN",
    "MD_COINGECKO_KEY",
    "MD_TWELVEDATA_KEY",
    "MD_FINNHUB_KEY",
    "MD_ALPHAVANTAGE_KEY",
)


class ConfigError(ValueError):
    """El entorno de arranque no se puede respetar."""


def _int_env(env: Dict[str, str], name: str, default: int, minimum: int, maximum: int) -> int:
    raw = (env.get(name) or "").strip()
    if not raw:
        return default
    try:
        value = int(raw, 10)
    except ValueError:
        raise ConfigError(f"{name}={raw!r} no es un entero (por defecto {default}).") from None
    if not minimum <= value <= maximum:
        raise ConfigError(f"{name}={value} fuera de rango [{minimum}, {maximum}].")
    return value


def _secret(env: Dict[str, str], name: str) -> Optional[str]:
    value = (env.get(name) or "").strip()
    if not value:
        return None
    # Va en cabeceras o en la query: nada de espacios ni saltos de línea.
    if any(c.isspace() or ord(c) < 0x21 or ord(c) > 0x7E for c in value):
        raise ConfigError(f"{name} contiene caracteres no válidos.")
    return value


def dns_servers(env: Dict[str, str]) -> Optional[Tuple[str, ...]]:
    """MD_DNS_SERVERS como tupla de IPs (1 a 3, separadas por espacios o comas)."""
    raw = env.get("MD_DNS_SERVERS")
    if raw is None or not raw.strip():
        return None
    tokens = raw.replace(",", " ").split()
    if len(tokens) > MAX_DNS_SERVERS:
        raise ConfigError(f"MD_DNS_SERVERS: como máximo {MAX_DNS_SERVERS} servidores.")
    servers = []
    for token in tokens:
        try:
            servers.append(str(ipaddress.ip_address(token)))
        except ValueError:
            raise ConfigError(
                f"MD_DNS_SERVERS: {token!r} no es una IP (sin resolver no hay nombres)."
            ) from None
    return tuple(servers)


def ntfy_url(env: Dict[str, str]) -> Optional[str]:
    """MD_NTFY_URL validada: https, host sin credenciales y un topic en la ruta."""
    raw = (env.get("MD_NTFY_URL") or "").strip()
    if not raw:
        return None
    parts = urlsplit(raw)
    if parts.scheme != "https" or not parts.hostname:
        raise ConfigError("MD_NTFY_URL debe ser una URL https:// completa con topic.")
    if parts.username or parts.password or parts.query or parts.fragment:
        raise ConfigError("MD_NTFY_URL no admite credenciales, query ni fragmento.")
    if parts.port not in (None, 443):
        raise ConfigError("MD_NTFY_URL solo admite el puerto 443.")
    topic = parts.path.rstrip("/").rsplit("/", 1)[-1]
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", topic):
        raise ConfigError("MD_NTFY_URL debe acabar en el topic, p. ej. https://ntfy.sh/mitopic.")
    return raw.rstrip("/")


@dataclass(frozen=True)
class Config:
    port: int = 8080
    cache_path: str = DEFAULT_CACHE_PATH
    quote_ttl: int = 120
    http_timeout: int = 8
    dns_servers: Tuple[str, ...] = ()
    ntfy_url: Optional[str] = None
    ntfy_token: Optional[str] = None
    coingecko_key: Optional[str] = None
    twelvedata_key: Optional[str] = None
    finnhub_key: Optional[str] = None
    alphavantage_key: Optional[str] = None
    extra_hosts: frozenset = field(default_factory=frozenset)

    @property
    def allowed_hosts(self) -> frozenset:
        return BASE_ALLOWED_HOSTS | self.extra_hosts


def load(env: Optional[Dict[str, str]] = None) -> Config:
    env = dict(os.environ if env is None else env)
    ntfy = ntfy_url(env)
    extra = frozenset({urlsplit(ntfy).hostname.lower()}) if ntfy else frozenset()
    return Config(
        port=_int_env(env, "MD_PORT", 8080, 1, 65535),
        cache_path=(env.get("MD_CACHE_PATH") or "").strip() or DEFAULT_CACHE_PATH,
        quote_ttl=_int_env(env, "MD_QUOTE_TTL", 120, 1, 86400),
        http_timeout=_int_env(env, "MD_HTTP_TIMEOUT", 8, 1, 60),
        dns_servers=dns_servers(env) or (),
        ntfy_url=ntfy,
        ntfy_token=_secret(env, "MD_NTFY_TOKEN"),
        coingecko_key=_secret(env, "MD_COINGECKO_KEY"),
        twelvedata_key=_secret(env, "MD_TWELVEDATA_KEY"),
        finnhub_key=_secret(env, "MD_FINNHUB_KEY"),
        alphavantage_key=_secret(env, "MD_ALPHAVANTAGE_KEY"),
        extra_hosts=extra,
    )


def fallback_cache_path() -> str:
    """Ruta de caché alternativa cuando MD_CACHE_PATH no es escribible."""
    return os.path.join(tempfile.gettempdir(), "pf-market-data", "cache.sqlite")
