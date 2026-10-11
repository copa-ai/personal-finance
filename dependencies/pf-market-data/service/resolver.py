"""Da al invitado un resolver DNS, porque bajo nodo nadie más lo hace.

nodo no sirve DNS ni escribe /etc/resolv.conf en el invitado; el fichero que exporta
BuildKit está vacío o no existe, y glibc acaba preguntando a 127.0.0.1:53. La red
`"*"` que declara el servicio abre UDP/TCP 53, así que basta con nombrar resolvers
públicos. Se ejecuta una vez, como root, antes de que start.sh baje privilegios.

Regla (la misma que yt-transcript):
- MD_DNS_SERVERS definida: se escriben esos servidores, se ejecute donde se ejecute.
- No definida y existe /__config__ (lo escribe el nodo): DEFAULT_DNS_SERVERS.
- No definida y sin /__config__ (Docker, tests): no se toca nada.
"""

import os
import sys
from typing import Dict, Iterable, Optional, Tuple

import config

RESOLV_CONF = "/etc/resolv.conf"
NODE_CONFIG = "/__config__"


def plan(env: Dict[str, str], under_node: bool) -> Optional[Tuple[str, ...]]:
    explicit = config.dns_servers(env)
    if explicit:
        return explicit
    if under_node:
        return config.DEFAULT_DNS_SERVERS
    return None


def render(servers: Iterable[str]) -> str:
    # timeout:2 attempts:2 para que un resolver caído no retenga la petición 10 s.
    lines = ["# Escrito por pf-market-data al arrancar (service/resolver.py)."]
    lines += [f"nameserver {s}" for s in servers]
    lines.append("options timeout:2 attempts:2")
    return "\n".join(lines) + "\n"


def write(text: str, path: str = RESOLV_CONF) -> None:
    if os.path.islink(path):
        os.unlink(path)
    with open(path, "w", encoding="ascii") as handle:
        handle.write(text)
    os.chmod(path, 0o644)


def main(env: Optional[Dict[str, str]] = None, path: str = RESOLV_CONF,
         node_config: str = NODE_CONFIG) -> int:
    env = dict(os.environ if env is None else env)
    try:
        servers = plan(env, under_node=os.path.isfile(node_config))
    except config.ConfigError as e:
        sys.stderr.write(f"[pf-market-data] FATAL: {e}\n")
        return 2
    if servers is None:
        sys.stderr.write("[pf-market-data] fuera de nodo y sin MD_DNS_SERVERS: "
                         "se mantiene el resolver del runtime\n")
        return 0
    try:
        write(render(servers), path)
    except OSError as e:
        sys.stderr.write(f"[pf-market-data] FATAL: no se puede escribir {path}: {e}\n")
        return 2
    sys.stderr.write(f"[pf-market-data] resolvers DNS: {' '.join(servers)}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
