"""Relé de notificaciones a ntfy (MD_NTFY_URL = URL completa del topic).

Se publica en JSON contra la raíz del servidor (`POST https://host/` con `topic`),
que admite UTF-8 en título y mensaje; las cabeceras HTTP no.
"""

import json
from typing import List, Optional
from urllib.parse import urlsplit

from errors import ProviderError
from providers.base import Provider

PRIORITY = {"default": 3, "high": 4}


class Ntfy(Provider):
    name = "ntfy"
    per_minute = 30

    def __init__(self, *args, url: str, token: Optional[str] = None, **kwargs):
        super().__init__(*args, **kwargs)
        path = urlsplit(url).path.rstrip("/")
        self.topic = path.rsplit("/", 1)[-1]
        self.publish_url = url[: url.rstrip("/").rfind("/") + 1]
        self.token = token

    def payload(self, title: Optional[str], message: str, priority: str, tags: List[str]) -> dict:
        body = {"topic": self.topic, "message": message, "priority": PRIORITY[priority]}
        if title:
            body["title"] = title
        if tags:
            body["tags"] = tags
        return body

    def send(self, title: Optional[str], message: str, priority: str = "default",
             tags: Optional[List[str]] = None) -> None:
        headers = {"Content-Type": "application/json"}
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        body = json.dumps(self.payload(title, message, priority, tags or []),
                          ensure_ascii=False).encode("utf-8")
        resp = self._request(self.publish_url, headers, method="POST", body=body)
        if not 200 <= resp.status < 300:
            self.state.failure()
            raise ProviderError(f"ntfy: HTTP {resp.status}")
        self.state.success()
